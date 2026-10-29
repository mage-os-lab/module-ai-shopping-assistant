<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Eval;

use Generator;
use MageOS\AiBase\Api\AiClientInterface;
use MageOS\AiBase\Api\Data\ChatRequestInterface;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StoredReasoning;

/**
 * Plays scripted model rounds through the AI Base client contract, for the eval harness and unit tests.
 */
class ScriptedAiClient implements AiClientInterface
{
    public const SERVICE = 'scripted';

    private const PROMPT_TOKENS = 100;

    public array $calls = [];

    private array $rounds = [];

    public function __construct(
        private readonly \MageOS\AiBase\Api\Data\StreamChunkInterfaceFactory $chunkFactory,
        private readonly \MageOS\AiBase\Api\Data\ChatResponseInterfaceFactory $responseFactory,
        private readonly \MageOS\AiBase\Api\Data\ToolCallInterfaceFactory $toolCallFactory,
        private readonly \MageOS\AiBase\Api\Data\TokenUsageInterfaceFactory $usageFactory
    ) {
    }

    public function addTextRound(string $text, FinishReason $finishReason = FinishReason::Stop): self
    {
        $this->rounds[] = $this->round($text, [], [], $finishReason, null);
        return $this;
    }

    public function addToolRound(array $toolUses, string $text = '', array $reasoning = []): self
    {
        $this->rounds[] = $this->round($text, $toolUses, $reasoning, FinishReason::ToolCall, null);
        return $this;
    }

    public function addFailure(\Throwable $failure, string $textBefore = ''): self
    {
        $this->rounds[] = $this->round($textBefore, [], [], null, $failure);
        return $this;
    }

    public function streamChat(ChatRequestInterface $request, array $options = []): Generator
    {
        $this->calls[] = ['request' => $request, 'options' => $options];
        if ($this->rounds === []) {
            throw new \LogicException('ScriptedAiClient script exhausted');
        }
        $round = array_shift($this->rounds);
        foreach ($round['reasoning'] as $block) {
            if ((string)($block['thinking'] ?? '') !== '') {
                yield $this->chunkFactory->create(['type' => StreamChunkType::Thinking, 'text' => (string)$block['thinking']]);
            }
        }
        if ($round['text'] !== '') {
            yield $this->chunkFactory->create(['type' => StreamChunkType::Text, 'text' => $round['text']]);
        }
        if ($round['failure'] !== null) {
            throw $round['failure'];
        }
        $toolCalls = [];
        foreach ($round['tools'] as $toolUse) {
            $call = $this->toolCallFactory->create([
                'id' => (string)($toolUse['id'] ?? ''),
                'name' => (string)($toolUse['name'] ?? ''),
                'arguments' => is_array($toolUse['input'] ?? null) ? $toolUse['input'] : [],
            ]);
            $toolCalls[] = $call;
            yield $this->chunkFactory->create(['type' => StreamChunkType::ToolCall, 'toolCall' => $call]);
        }
        $usage = $this->usageFactory->create([
            'promptTokens' => self::PROMPT_TOKENS,
            'completionTokens' => max(1, (int)ceil(strlen($round['text']) / 4)),
        ]);
        yield $this->chunkFactory->create(['type' => StreamChunkType::Usage, 'usage' => $usage]);
        return $this->responseFactory->create([
            'text' => $round['text'],
            'toolCalls' => $toolCalls,
            'usage' => $usage,
            'finishReason' => $round['finish'],
            'reasoning' => array_map(
                static fn (array $block): StoredReasoning => new StoredReasoning(
                    (string)($block['thinking'] ?? ''),
                    (string)($block['signature'] ?? '')
                ),
                $round['reasoning']
            ),
        ]);
    }

    public function chat(ChatRequestInterface $request, array $options = []): ChatResponseInterface
    {
        $stream = $this->streamChat($request, $options);
        iterator_to_array($stream, false);
        return $stream->getReturn();
    }

    public function complete(string $prompt, array $options = []): string
    {
        throw new \LogicException('ScriptedAiClient only plays back chat rounds.');
    }

    public function getServiceCode(): string
    {
        return self::SERVICE;
    }

    public function getServiceId(): string
    {
        return self::SERVICE;
    }

    public function getModel(): string
    {
        return 'scripted-model';
    }

    public function getConsumer(): string
    {
        return 'MageOS_AiShoppingAssistant';
    }

    private function round(
        string $text,
        array $tools,
        array $reasoning,
        ?FinishReason $finish,
        ?\Throwable $failure
    ): array {
        return [
            'text' => $text,
            'tools' => $tools,
            'reasoning' => $reasoning,
            'finish' => $finish,
            'failure' => $failure,
        ];
    }
}
