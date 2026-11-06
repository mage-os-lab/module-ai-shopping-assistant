<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Controller\Result;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Controller\Result\EventStream;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Orchestrator;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Limits\SlotHandle;
use MageOS\AiShoppingAssistant\Model\Session\Binding;
use Psr\Log\LoggerInterface;

class EventStreamTest extends \PHPUnit\Framework\TestCase
{
    private Registry $registry;

    protected function setUp(): void
    {
        $this->registry = new Registry();
        if (!class_exists(Orchestrator::class)) {
            $this->markTestSkipped('Turn\\Orchestrator is not present on disk yet (task T9 has not landed).');
        }
    }

    private function storeConfig(): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Test Store');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        return new StoreConfig($scopeConfig, $storeManager);
    }

    private function binding(): Binding
    {
        $page = $this->createMock(PageContextInterface::class);
        $context = new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
        return new Binding('sess-1', ['turns' => 0], new SessionState(), false, 0, $context);
    }

    private function context(): SessionContext
    {
        $page = $this->createMock(PageContextInterface::class);
        return new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
    }

    /**
     * @param Event[] $events
     */
    private function generatorOf(array $events): \Generator
    {
        foreach ($events as $event) {
            yield $event;
        }
    }

    private function throwingGenerator(): \Generator
    {
        yield Event::textDelta('partial');
        throw new \RuntimeException('boom');
    }

    private function throwingGeneratorWithApiKeyInMessage(): \Generator
    {
        yield Event::textDelta('partial');
        throw new \RuntimeException('bad key sk-ant-abc123XYZ used');
    }

    private function responseMock(): HttpResponse
    {
        $response = $this->createMock(HttpResponse::class);
        $response->method('sendHeaders')->willReturn(true);
        return $response;
    }

    private function buildResultCapturingWrites(
        Binding $binding,
        string $message,
        SessionContext $context,
        ?SlotHandle $slot,
        ?Event $busyEvent,
        Orchestrator $orchestrator,
        StoreConfig $storeConfig,
        LoggerInterface $logger
    ): EventStream {
        $result = new class($orchestrator, $storeConfig, $logger, $this->registry) extends EventStream {
            private array $capturedWrites = [];

            public function getWrites(): array
            {
                return $this->capturedWrites;
            }

            protected function write(string $frame): void
            {
                $this->capturedWrites[] = $frame;
            }

            protected function drainOutputBuffers(): void
            {
            }
        };
        if ($busyEvent !== null) {
            $result->setBusy($busyEvent);
        }
        return $result->setTurn($binding, $message, $context, $slot);
    }

    private function buildResultReportingCompressionOn(
        Binding $binding,
        string $message,
        SessionContext $context,
        ?SlotHandle $slot,
        ?Event $busyEvent,
        Orchestrator $orchestrator,
        StoreConfig $storeConfig,
        LoggerInterface $logger
    ): EventStream {
        $result = new class($orchestrator, $storeConfig, $logger, $this->registry) extends EventStream {
            protected function compressionIsOn(): bool
            {
                return true;
            }
        };
        if ($busyEvent !== null) {
            $result->setBusy($busyEvent);
        }
        return $result->setTurn($binding, $message, $context, $slot);
    }

    public function testAClientThatLeavesStopsTheWritesButTheTurnRunsToTheEnd(): void
    {
        $consumed = [];
        $generator = (static function () use (&$consumed): \Generator {
            foreach (['cards', 'text', 'done'] as $step) {
                $consumed[] = $step;
                yield $step === 'done' ? Event::turnComplete('end_turn', [], 10, 0) : Event::textDelta($step);
            }
        })();
        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($generator);
        $orchestrator->expects($this->once())->method('interrupt');
        $orchestrator->expects($this->once())->method('finishedAfterInterrupt');
        $result = new class($orchestrator, $this->storeConfig(), $this->createMock(LoggerInterface::class), $this->registry) extends EventStream {
            public array $writes = [];

            protected function write(string $frame): void
            {
                $this->writes[] = $frame;
            }

            protected function drainOutputBuffers(): void
            {
            }

            protected function clientLeft(): bool
            {
                return count($this->writes) >= 2;
            }
        };
        $result->setTurn($this->binding(), 'hello', $this->context(), null);

        $result->renderResult($this->responseMock());

        $this->assertSame(['cards', 'text', 'done'], $consumed);
        $this->assertCount(2, $result->writes);
        $this->assertStringContainsString('cards', $result->writes[1]);
    }

    public function testTwoEventsWriteTheOpenCommentTwoFramesAndReleaseTheSlot(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $events = [Event::textDelta('hi'), Event::turnComplete('end_turn', [], 10, 0)];
        $orchestrator->method('streamTurn')->willReturn($this->generatorOf($events));

        $result = $this->buildResultCapturingWrites(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $this->storeConfig(),
            $this->createMock(LoggerInterface::class)
        );

        $result->renderResult($this->responseMock());
        $writes = $result->getWrites();

        $this->assertCount(3, $writes);
        $this->assertSame(": open\n\n", $writes[0]);
        $this->assertStringContainsString('event: text_delta', $writes[1]);
        $this->assertStringContainsString('event: turn_complete', $writes[2]);
    }

    public function testBusyEventWritesTheOpenCommentTheErrorAndACompletionFrameWithoutCallingTheOrchestrator(): void
    {
        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->expects($this->never())->method('streamTurn');

        $result = $this->buildResultCapturingWrites(
            $this->binding(),
            'hello',
            $this->context(),
            null,
            Event::error('The assistant is busy right now. Please try again in a few seconds.', 5),
            $orchestrator,
            $this->storeConfig(),
            $this->createMock(LoggerInterface::class)
        );

        $result->renderResult($this->responseMock());
        $writes = $result->getWrites();

        $this->assertCount(3, $writes);
        $this->assertSame(": open\n\n", $writes[0]);
        $this->assertStringContainsString('event: error', $writes[1]);
        $this->assertStringContainsString('event: turn_complete', $writes[2]);
    }

    public function testCompressionOnProducesTheJsonFallbackWithTheUnavailableHeader(): void
    {
        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->expects($this->never())->method('streamTurn');

        $response = $this->createMock(HttpResponse::class);
        $response->expects($this->once())
            ->method('setHeader')
            ->with('X-AiAgent-Stream', 'unavailable', true);
        $response->expects($this->once())->method('representJson');

        $result = $this->buildResultReportingCompressionOn(
            $this->binding(),
            'hello',
            $this->context(),
            null,
            Event::error('The assistant is busy right now. Please try again in a few seconds.', 5),
            $orchestrator,
            $this->storeConfig(),
            $this->createMock(LoggerInterface::class)
        );

        $this->assertSame($result, $result->renderResult($response));
    }

    public function testAStreamedTurnTurnsThePageCacheResultPluginOff(): void
    {
        $this->registry->register('use_page_cache_plugin', true, true);
        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($this->generatorOf([Event::turnComplete('end_turn', [], 10, 0)]));

        $result = $this->buildResultCapturingWrites(
            $this->binding(),
            'hello',
            $this->context(),
            null,
            null,
            $orchestrator,
            $this->storeConfig(),
            $this->createMock(LoggerInterface::class)
        );
        $result->renderResult($this->responseMock());

        $this->assertFalse($this->registry->registry('use_page_cache_plugin'));
    }

    public function testTheJsonFallbackLeavesThePageCacheResultPluginOn(): void
    {
        $this->registry->register('use_page_cache_plugin', true, true);
        $response = $this->createMock(HttpResponse::class);

        $result = $this->buildResultReportingCompressionOn(
            $this->binding(),
            'hello',
            $this->context(),
            null,
            Event::error('The assistant is busy right now. Please try again in a few seconds.', 5),
            $this->createMock(Orchestrator::class),
            $this->storeConfig(),
            $this->createMock(LoggerInterface::class)
        );
        $result->renderResult($response);

        $this->assertTrue($this->registry->registry('use_page_cache_plugin'));
    }

    public function testAThrowingGeneratorWritesAnErrorFrameAndStillReleasesTheSlot(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($this->throwingGenerator());

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $result = $this->buildResultCapturingWrites(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $this->storeConfig(),
            $logger
        );

        $result->renderResult($this->responseMock());
        $writes = $result->getWrites();

        $this->assertCount(3, $writes);
        $this->assertSame(": open\n\n", $writes[0]);
        $this->assertStringContainsString('event: text_delta', $writes[1]);
        $this->assertStringContainsString('event: error', $writes[2]);
    }

    public function testErrorLogMasksAnApiKeyAndIncludesTheExceptionClass(): void
    {
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects($this->once())->method('unlock')->with('test-slot');
        $slot = new SlotHandle($lockManager, 'test-slot');

        $orchestrator = $this->createMock(Orchestrator::class);
        $orchestrator->method('streamTurn')->willReturn($this->throwingGeneratorWithApiKeyInMessage());

        $logger = $this->createMock(LoggerInterface::class);
        $captured = null;
        $logger->expects($this->once())->method('error')->with(
            $this->callback(function (string $message) use (&$captured): bool {
                $captured = $message;
                return true;
            })
        );

        $result = $this->buildResultCapturingWrites(
            $this->binding(),
            'hello',
            $this->context(),
            $slot,
            null,
            $orchestrator,
            $this->storeConfig(),
            $logger
        );

        $result->renderResult($this->responseMock());

        $this->assertStringContainsString('RuntimeException', $captured);
        $this->assertStringContainsString('sk-ant-***', $captured);
        $this->assertStringNotContainsString('sk-ant-abc123XYZ', $captured);
    }
}
