<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Controller\Result;

use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\Framework\Controller\AbstractResult;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\Event\SseFrame;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Limits\SlotHandle;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

/**
 * Stays non-final so a unit test can override write() and drainOutputBuffers(), the two
 * points that touch the real output stream, and observe frames without fighting PHPUnit's
 * own output buffer. Per-request state arrives through setTurn()/setBusy() rather than the
 * constructor, because Magento's compiled factory drops null constructor arguments.
 * A streamed response turns the page cache result plugin off: it would set a cookie after
 * the stream has sent its headers, and the warning appends an error page to the stream.
 */
class EventStream extends AbstractResult
{
    private ?Binding $binding = null;

    private ?string $message = null;

    private ?SessionContext $context = null;

    private ?SlotHandle $slot = null;

    private ?Event $busyEvent = null;

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator $orchestrator,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \Magento\Framework\Registry $registry
    ) {
    }

    public function setTurn(Binding $binding, string $message, SessionContext $context, ?SlotHandle $slot): self
    {
        $this->binding = $binding;
        $this->message = $message;
        $this->context = $context;
        $this->slot = $slot;
        return $this;
    }

    public function setBusy(Event $busyEvent): self
    {
        $this->busyEvent = $busyEvent;
        return $this;
    }

    protected function render(HttpResponseInterface $response)
    {
        $this->registerSlotReleaseFallback();
        try {
            if ($this->context === null && $this->busyEvent === null) {
                throw new \LogicException('EventStream requires setTurn() or setBusy() to be called before render().');
            }
            if (!headers_sent()) {
                ini_set('zlib.output_compression', '0');
            }
            ignore_user_abort(true);
            if ($this->compressionIsOn()) {
                return $this->renderJsonFallback($response);
            }
            $this->registry->unregister('use_page_cache_plugin');
            $this->registry->register('use_page_cache_plugin', false);
            $this->sendStreamHeaders($response);
            $this->drainOutputBuffers();
            $this->write(": open\n\n");
            if ($this->busyEvent !== null) {
                $this->write(SseFrame::encode($this->busyEvent));
                $this->write(SseFrame::encode($this->busyCompletion()));
                $response->setBody('');
                return $this;
            }
            $this->runTurn($response);
            return $this;
        } finally {
            $this->slot?->release();
        }
    }

    private function registerSlotReleaseFallback(): void
    {
        $slot = $this->slot;
        if ($slot === null) {
            return;
        }
        register_shutdown_function(static function () use ($slot): void {
            $slot->release();
        });
    }

    private function runTurn(HttpResponseInterface $response): void
    {
        $agentConfig = $this->storeConfig->agent($this->context->storeId);
        $lastBeat = time();
        $clientLeft = false;
        $onWaiting = function () use (&$lastBeat, &$clientLeft, $agentConfig): void {
            $now = time();
            if ($clientLeft || $now - $lastBeat < $agentConfig->heartbeatSeconds) {
                return;
            }
            $this->write(": ping\n\n");
            $lastBeat = $now;
        };
        try {
            foreach ($this->orchestrator->streamTurn($this->binding, $this->message, $this->context, $onWaiting) as $event) {
                if ($clientLeft) {
                    continue;
                }
                $this->write(SseFrame::encode($event));
                if (!$this->clientLeft()) {
                    continue;
                }
                $clientLeft = true;
                $this->orchestrator->interrupt($this->binding);
            }
            if ($clientLeft) {
                $this->orchestrator->finishedAfterInterrupt($this->binding);
            }
        } catch (\Throwable $exception) {
            $this->logError($exception);
            $this->write(SseFrame::encode(Event::error('Something went wrong. Please try again.')));
        } finally {
            $this->slot?->release();
        }
        $response->setBody('');
    }

    private function renderJsonFallback(HttpResponseInterface $response): self
    {
        $events = $this->busyEvent !== null
            ? [$this->busyEvent, $this->busyCompletion()]
            : $this->collectEvents();
        $response->setHeader('X-AiAgent-Stream', 'unavailable', true);
        $response->setNoCacheHeaders();
        $response->setMetadata('NotCacheable', true);
        $body = json_encode(
            ['events' => array_map(static fn (Event $event): array => $event->toArray(), $events)],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $response->representJson($body !== false ? $body : '{"events":[]}');
        return $this;
    }

    private function collectEvents(): array
    {
        $events = [];
        try {
            foreach ($this->orchestrator->streamTurn($this->binding, $this->message, $this->context) as $event) {
                $events[] = $event;
            }
        } catch (\Throwable $exception) {
            $this->logError($exception);
            $events[] = Event::error('Something went wrong. Please try again.');
        } finally {
            $this->slot?->release();
        }
        return $events;
    }

    private function busyCompletion(): Event
    {
        return Event::turnComplete('busy', [], 0, 0);
    }

    private function logError(\Throwable $exception): void
    {
        $masked = preg_replace('/sk-ant-[A-Za-z0-9_-]+/', 'sk-ant-***', $exception->getMessage());
        $message = $masked !== null ? $masked : $exception->getMessage();
        $this->logger->error(get_class($exception) . ': ' . $message, ['exception' => $exception]);
    }

    private function sendStreamHeaders(HttpResponseInterface $response): void
    {
        $response->setHeader('Content-Type', 'text/event-stream; charset=utf-8', true);
        $response->setHeader('X-Accel-Buffering', 'no', true);
        $response->setHeader('Connection', 'keep-alive', true);
        $response->setNoCacheHeaders();
        $response->setMetadata('NotCacheable', true);
        $response->sendHeaders();
    }

    protected function compressionIsOn(): bool
    {
        return filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN);
    }

    protected function drainOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            if (!ob_end_flush()) {
                break;
            }
        }
    }

    protected function write(string $frame): void
    {
        echo $frame;
        flush();
    }

    protected function clientLeft(): bool
    {
        return connection_aborted() === 1;
    }
}
