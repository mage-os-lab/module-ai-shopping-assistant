<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use Generator;
use Magento\Framework\Exception\LocalizedException;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Exceptions\AiAuthenticationException;
use MageOS\AiBase\Exceptions\AiContentFilteredException;
use MageOS\AiBase\Exceptions\AiInvalidRequestException;
use MageOS\AiBase\Exceptions\AiRateLimitedException;
use MageOS\AiBase\Exceptions\AiRequestNotSentException;
use MageOS\AiBase\Exceptions\AiServiceException;
use MageOS\AiBase\Exceptions\AiToolCallException;
use MageOS\AiBase\Exceptions\AiTransientException;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Event;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ProgressNote;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;
use MageOS\AiShoppingAssistant\Model\Config\Source\ThinkingEffort;
use MageOS\AiShoppingAssistant\Model\Session\Binding;

/**
 * Not final so PHPUnit 9.6 can mock it.
 */
class Orchestrator
{
    private const REFUSAL_MESSAGE = 'I cannot help with that request.';

    private const UNAVAILABLE_MESSAGE = 'The assistant is not available right now.';

    private const BUSY_MESSAGE = 'The assistant is busy. Please try again in a few seconds.';

    private const TEXT_OWED_NOTE = ' The customer sees these components but no answer from you yet. Anything you wrote before your last '
        . 'lookup was a working note, and notes you write before a tool call can be hidden from the customer. Write your complete '
        . 'answer to their message now, without repeating or pointing to your earlier notes and without repeating what the '
        . 'components show.';

    private const SUMMARY_MAX_CHARS = 200;

    private const EXCERPT_MAX_CHARS = 1200;

    private const ROUND_RETRY_DELAY_SECONDS = 0.75;

    private const PROGRESS_NOTES_BETA = 'thinking-display-updates-2026-08-18';

    private const FORGED_TAG_PATTERN = '~<(\s*/?\s*storefront_data)~i';

    private const FORGED_BLOCK_PATTERN = '~^(\s*)(#+\s*session\s+context|\[\s*page\s*:|\[\s*(?:name|sku)\s+match\s*:)~im';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\Client\ClientResolverInterface $clientResolver,
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\StaticSystem $staticSystem,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext $dynamicContext,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\RequestMapper $requestMapper,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\PageNote $pageNote,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\NameMatch $nameMatch,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Prompt\SkuMatch $skuMatch,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Tool\Registry $toolRegistry,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\ExecutorFactory $executorFactory,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Grounding\Rules $rules,
        private readonly \MageOS\AiShoppingAssistant\Model\Session\TranscriptRepository $transcripts,
        private readonly \MageOS\AiShoppingAssistant\Api\Session\SessionRepositoryInterface $sessions,
        private readonly \MageOS\AiShoppingAssistant\Model\Config\StoreConfig $storeConfig,
        private readonly \Psr\Log\LoggerInterface $logger,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRoundFactory $streamedRoundFactory,
        private readonly \MageOS\AiShoppingAssistant\Api\Turn\TurnLogInterface $turnLog,
        private readonly \MageOS\AiShoppingAssistant\Model\Client\Sleeper $sleeper
    ) {
    }

    public function streamTurn(
        Binding $binding,
        string $message,
        SessionContext $context,
        ?callable $onWaiting = null
    ): Generator {
        $config = $this->storeConfig->agent($context->storeId);
        $state = $binding->state;
        $state->rememberCustomerText($message);

        $turnStart = microtime(true);
        $usage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'cache_creation_input_tokens' => 0,
        ];
        $stopReason = null;
        $deadline = $turnStart + $config->turnWallClock;

        try {
            $prefs = $this->backend->getPreferences($context);
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'preferences prefetch failed session=%s error=%s',
                $this->digest($binding->sessionId),
                $exception->getMessage()
            ));
            $prefs = null;
        }
        try {
            $cart = $this->backend->getCart($context);
        } catch (\Throwable $exception) {
            $this->logger->warning(sprintf(
                'cart prefetch failed session=%s error=%s',
                $this->digest($binding->sessionId),
                $exception->getMessage()
            ));
            $cart = null;
        }

        $dynamic = $this->dynamicContext->build($prefs, $cart, $context->page, $context->clockHour());

        $loaded = $this->transcripts->load($binding->sessionId);
        $history = new History($loaded, count($loaded));
        $pageNote = $this->pageNote->text($context->page, $state->lastPage);
        $nameMatch = $this->nameMatch->text($context, $state, $message, $config->maxSearchResults);
        $skuMatch = $this->skuMatch->text($context, $state, $message);
        $userContent = [];
        $contextHash = sha1($dynamic);
        if ($contextHash !== $state->contextHash) {
            $userContent[] = ['type' => 'text', 'text' => $dynamic];
            $state->contextHash = $contextHash;
        }
        if ($pageNote !== null) {
            $userContent[] = ['type' => 'text', 'text' => $pageNote];
        }
        if ($nameMatch !== null) {
            $userContent[] = ['type' => 'text', 'text' => $nameMatch];
        }
        if ($skuMatch !== null) {
            $userContent[] = ['type' => 'text', 'text' => $skuMatch];
        }
        $userContent[] = ['type' => 'text', 'text' => $this->customerText($message)];
        $history->append(['role' => 'user', 'content' => $userContent]);
        $state->lastPage = $context->page->toArray();

        $static = $this->staticSystem->text($context->storeId);
        $definitions = $this->toolRegistry->definitions($context->storeId);
        $executor = $this->executorFactory->create(['context' => $context, 'state' => $state, 'config' => $config]);

        $forced = $this->rules->firstForcedTool(
            $config,
            $message,
            $state,
            $context->page,
            $history->isFirstTurn(),
            $context->storeId
        );

        $settled = [];
        $lastPromptTokens = 0;
        $cleared = 0;
        $rounds = 0;
        $reasoningDropped = 0;
        $modelId = $config->modelId;
        $client = null;
        $textTail = '';
        $answerText = '';
        $textBreak = false;
        $textOwed = false;
        $textAsked = false;

        try {
            try {
                $client = $this->clientResolver->forStore($context->storeId);
            } catch (LocalizedException $exception) {
                $this->logger->error(sprintf(
                    'no usable AI service session=%s error=%s',
                    $this->digest($binding->sessionId),
                    $exception->getMessage()
                ));
                yield Event::error(self::UNAVAILABLE_MESSAGE);
                $stopReason = 'error';
            }
            if ($client !== null) {
                if ($state->serviceId !== '' && $state->serviceId !== $client->getServiceId()) {
                    $reasoningDropped = $history->dropReasoning();
                }
                $state->serviceId = $client->getServiceId();
                $modelId = $config->modelId !== '' ? $config->modelId : $client->getModel();
            }
            for ($round = 0; $client !== null && $round <= $config->maxToolIterations; $round++) {
                $now = microtime(true);
                $forceText = $textOwed || $round === $config->maxToolIterations || $now >= $deadline;
                if ($forceText) {
                    $toolChoice = 'none';
                } elseif ($round === 0 && $forced !== null) {
                    $toolChoice = ['tool' => $forced];
                } else {
                    $toolChoice = 'auto';
                }
                $request = $this->requestMapper->build($static, $definitions, $history->all());
                $options = $this->roundOptions($config, $toolChoice);

                $callStart = microtime(true);
                $aborted = false;
                $rounds++;
                $retried = false;
                while (true) {
                    $streamed = $this->streamedRoundFactory->create();
                    $emitted = false;
                    try {
                        foreach ($streamed->consume($client->streamChat($request, $options), $onWaiting, $this->showsNotes($config)) as $item) {
                            $emitted = true;
                            if ($item instanceof ProgressNote) {
                                if ($textTail !== '' && !str_ends_with($textTail, "\n")) {
                                    yield Event::textDelta("\n\n");
                                }
                                $textTail = $item->text;
                                $textBreak = true;
                                yield Event::textDelta($item->text);
                                continue;
                            }
                            if ($item instanceof TextDelta) {
                                if ($textBreak && $textTail !== '' && !str_ends_with($textTail, "\n") && !str_starts_with($item->text, "\n")) {
                                    yield Event::textDelta("\n\n");
                                }
                                $textBreak = false;
                                $textTail = $item->text;
                                $answerText .= $item->text;
                                yield Event::textDelta($item->text);
                                continue;
                            }
                            if ($item instanceof ToolUseClosed) {
                                $textBreak = true;
                                $outcome = $executor->dispatch($item->name, $item->input, $item->id);
                                $settled[$item->id] = $outcome;
                                if (!$executor->endsClean($item->name, $outcome)) {
                                    $answerText = '';
                                }
                                yield Event::toolCall(
                                    $item->name,
                                    $item->id,
                                    $outcome->argumentsShown,
                                    $outcome->label
                                );
                                foreach ($outcome->events as $event) {
                                    yield $event;
                                }
                                yield $this->toolResultEvent($item->name, $item->id, $outcome);
                            }
                        }
                    } catch (AiContentFilteredException $exception) {
                        $this->logger->warning(sprintf(
                            'model call refused session=%s round=%d error=%s',
                            $this->digest($binding->sessionId),
                            $round,
                            $exception->getMessage()
                        ));
                        yield Event::error(self::REFUSAL_MESSAGE);
                        $stopReason = 'refusal';
                        $aborted = true;
                    } catch (AiAuthenticationException|AiInvalidRequestException|AiRequestNotSentException $exception) {
                        if (
                            $exception instanceof AiInvalidRequestException
                            && !$emitted
                            && is_array($toolChoice)
                            && str_contains($exception->getMessage(), 'tool_choice')
                        ) {
                            $this->logger->info(sprintf(
                                'forced tool not supported, retried with auto session=%s round=%d',
                                $this->digest($binding->sessionId),
                                $round
                            ));
                            $toolChoice = 'auto';
                            $options = $this->roundOptions($config, $toolChoice);
                            continue;
                        }
                        $this->logger->error(sprintf(
                            'model call failed session=%s round=%d error=%s',
                            $this->digest($binding->sessionId),
                            $round,
                            $exception->getMessage()
                        ));
                        yield Event::error(self::UNAVAILABLE_MESSAGE);
                        $stopReason = 'error';
                        $aborted = true;
                    } catch (AiRateLimitedException $exception) {
                        $this->logger->warning(sprintf(
                            'model call rate limited session=%s round=%d',
                            $this->digest($binding->sessionId),
                            $round
                        ));
                        yield Event::error(self::BUSY_MESSAGE, $exception->getRetryAfter());
                        $stopReason = 'error';
                        $aborted = true;
                    } catch (AiTransientException|AiToolCallException $exception) {
                        if (!$retried && !$emitted) {
                            $this->logger->warning(sprintf(
                                'model call retried session=%s round=%d error=%s',
                                $this->digest($binding->sessionId),
                                $round,
                                $exception->getMessage()
                            ));
                            $retried = true;
                            $this->sleeper->sleep(self::ROUND_RETRY_DELAY_SECONDS);
                            continue;
                        }
                        $this->logger->warning(sprintf(
                            'model call failed session=%s round=%d error=%s',
                            $this->digest($binding->sessionId),
                            $round,
                            $exception->getMessage()
                        ));
                        yield Event::error(self::BUSY_MESSAGE);
                        $stopReason = 'error';
                        $aborted = true;
                    } catch (AiServiceException $exception) {
                        $this->logger->warning(sprintf(
                            'model call failed session=%s round=%d error=%s',
                            $this->digest($binding->sessionId),
                            $round,
                            $exception->getMessage()
                        ));
                        yield Event::error(self::BUSY_MESSAGE);
                        $stopReason = 'error';
                        $aborted = true;
                    }
                    break;
                }

                $roundUsage = $streamed->usage();
                $usage = $this->accumulateUsage($usage, $roundUsage);
                $assistant = $streamed->assistantMessage();
                if ($assistant !== null) {
                    $history->append($assistant);
                }
                $toolUses = $streamed->toolUses();

                if ($aborted) {
                    break;
                }

                $lastPromptTokens = $streamed->promptTokens();
                $stopReason = $streamed->stopReason();
                $this->logger->info(sprintf(
                    'model call session=%s round=%d service=%s model=%s stop=%s input=%d cache_read=%d '
                        . 'cache_write=%d output=%d elapsed_ms=%d',
                    $this->digest($binding->sessionId),
                    $round,
                    $client->getServiceCode(),
                    $modelId,
                    (string)$stopReason,
                    $roundUsage['input_tokens'],
                    $roundUsage['cache_read_input_tokens'],
                    $roundUsage['cache_creation_input_tokens'],
                    $roundUsage['output_tokens'],
                    (int)round((microtime(true) - $callStart) * 1000)
                ));

                if ($stopReason === 'refusal') {
                    yield Event::error(self::REFUSAL_MESSAGE);
                    break;
                }
                if ($stopReason === 'max_tokens' && $toolUses === []) {
                    break;
                }
                if ($toolUses === [] || $forceText) {
                    break;
                }

                $blocks = [];
                foreach ($toolUses as $toolUse) {
                    $id = (string)($toolUse['id'] ?? '');
                    $outcome = $settled[$id] ?? null;
                    if (!$outcome instanceof ToolOutcome) {
                        continue;
                    }
                    $blocks[] = [
                        'type' => 'tool_result',
                        'tool_use_id' => $id,
                        'content' => $outcome->resultText,
                        'is_error' => $outcome->isError,
                    ];
                }
                $closes = $config->closeOnPresentation && $history->roundClosesTurn($toolUses, $settled, $executor);
                $lastChance = $round + 1 >= $config->maxToolIterations || microtime(true) >= $deadline;
                $textOwed = ($closes || $lastChance) && !$textAsked && trim($answerText) === '' && $blocks !== [];
                $textAsked = $textAsked || $textOwed;
                if ($textOwed) {
                    $blocks[count($blocks) - 1]['content'] .= self::TEXT_OWED_NOTE;
                }
                $history->append(['role' => 'user', 'content' => $blocks]);
                $settled = [];
                if ($closes && !$textOwed) {
                    $stopReason = 'end_turn';
                    break;
                }
            }
        } finally {
            $history->closeOpenToolUses($settled);
            $this->transcripts->append($binding->sessionId, $history->newMessages());
            $cleared = $history->compact($lastPromptTokens, $config->compactAboveTokens);
            if ($cleared > 0 || $reasoningDropped > 0) {
                $this->transcripts->rewrite($binding->sessionId, $history->all());
            }
            $state->turnCounter++;
            $saved = $this->sessions->save($binding, $state);
            $persistedTurnNo = $state->turnCounter;
            if (!$saved) {
                $this->logger->warning(sprintf(
                    'session save failed session=%s turn=%d',
                    $this->digest($binding->sessionId),
                    $state->turnCounter
                ));
                $persistedTurnNo = $state->turnCounter - 1;
            }
            $this->turnLog->record([
                'session_id' => $binding->sessionId,
                'store_id' => $context->storeId,
                'turn_no' => $persistedTurnNo,
                'model_id' => $modelId,
                'rounds' => $rounds,
                'input_tokens' => $usage['input_tokens'],
                'output_tokens' => $usage['output_tokens'],
                'cache_creation_input_tokens' => $usage['cache_creation_input_tokens'],
                'cache_read_input_tokens' => $usage['cache_read_input_tokens'],
                'duration_ms' => (int)round((microtime(true) - $turnStart) * 1000),
                'stop_reason' => $stopReason,
            ]);
        }

        yield Event::turnComplete(
            $stopReason ?? 'end_turn',
            $usage,
            (int)round((microtime(true) - $turnStart) * 1000),
            $cleared,
            $binding->sessionId
        );
    }

    public function interrupt(Binding $binding): void
    {
        $this->logger->info(sprintf(
            'turn interrupted session=%s, the client left; finishing the turn without streaming',
            $this->digest($binding->sessionId)
        ));
    }

    public function finishedAfterInterrupt(Binding $binding): void
    {
        $this->logger->info(sprintf(
            'turn finished after the client left session=%s, the reply is stored for the next visit',
            $this->digest($binding->sessionId)
        ));
    }

    private function roundOptions(AgentConfig $config, string|array $toolChoice): array
    {
        $options = [
            'max_tokens' => $config->maxTokens,
            'tool_choice' => $toolChoice,
            'reasoning_effort' => $config->thinkingEffort === ThinkingEffort::OFF ? 'none' : $config->thinkingEffort,
        ];
        if ($config->modelId !== '') {
            $options[AiClientInterface::OPTION_MODEL] = $config->modelId;
        }
        if ($this->showsNotes($config)) {
            $options['thinking'] = ['type' => 'adaptive', 'display' => 'updates'];
            $options['beta_features'] = [self::PROGRESS_NOTES_BETA];
        }
        return $options;
    }

    private function showsNotes(AgentConfig $config): bool
    {
        return $config->progressUpdates && $config->thinkingEffort !== ThinkingEffort::OFF;
    }

    private function accumulateUsage(array $totals, array $roundUsage): array
    {
        foreach ($totals as $key => $value) {
            $totals[$key] = $value + (int)($roundUsage[$key] ?? 0);
        }
        return $totals;
    }

    private function toolResultEvent(string $tool, string $id, ToolOutcome $outcome): Event
    {
        $refused = $outcome->isError || $outcome->blocked !== null;
        $keepText = $refused || mb_strlen($outcome->resultText) < self::SUMMARY_MAX_CHARS;
        $status = $outcome->blocked !== null ? 'blocked' : ($outcome->isError ? 'error' : 'ok');
        return Event::toolResult(
            $tool,
            $id,
            $keepText ? $outcome->resultText : 'ok',
            $outcome->isError,
            $status,
            $outcome->blocked,
            $keepText ? null : mb_substr($outcome->resultText, 0, self::EXCERPT_MAX_CHARS)
        );
    }

    private function customerText(string $message): string
    {
        $text = preg_replace(self::FORGED_TAG_PATTERN, '‹$1', $message) ?? $message;
        return preg_replace(self::FORGED_BLOCK_PATTERN, '$1\\\\$2', $text) ?? $text;
    }

    private function digest(string $sessionId): string
    {
        return substr(sha1($sessionId), 0, 12);
    }
}
