<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn;

use Generator;
use MageOS\AiBase\Api\Data\ChatResponseInterface;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\StreamChunkInterface;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Api\Data\TokenUsageInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ProgressNote;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;

/**
 * With progress notes on, a thinking block that carries text is a note the model wrote before a lookup.
 * The note is collected until its block ends and then shown, except the stand-in for interrupted work.
 */
class StreamedRound
{
    private const INTERRUPTED_NOTE = 'This part of the response was interrupted before it finished.';

    private string $text = '';

    private string $note = '';

    private array $toolUses = [];

    private ?TokenUsageInterface $chunkUsage = null;

    private ?ChatResponseInterface $response = null;

    public function consume(Generator $stream, ?callable $onWaiting = null, bool $showNotes = false): Generator
    {
        foreach ($stream as $chunk) {
            if ($onWaiting !== null) {
                $onWaiting();
            }
            if (!$chunk instanceof StreamChunkInterface) {
                continue;
            }
            $this->chunkUsage = $chunk->getUsage() ?? $this->chunkUsage;
            if ($chunk->getType() === StreamChunkType::Thinking) {
                $this->note .= $showNotes ? $chunk->getText() : '';
                continue;
            }
            yield from $this->flushNote();
            if ($chunk->getType() === StreamChunkType::Text && $chunk->getText() !== '') {
                $this->text .= $chunk->getText();
                yield new TextDelta($chunk->getText());
                continue;
            }
            $call = $chunk->getType() === StreamChunkType::ToolCall ? $chunk->getToolCall() : null;
            if ($call === null) {
                continue;
            }
            $this->toolUses[] = ['id' => $call->getId(), 'name' => $call->getName(), 'input' => $call->getArguments()];
            yield new ToolUseClosed($call->getId(), $call->getName(), $call->getArguments());
        }
        yield from $this->flushNote();
        $response = $stream->getReturn();
        $this->response = $response instanceof ChatResponseInterface ? $response : null;
    }

    public function assistantMessage(): ?array
    {
        $content = [];
        foreach ($this->response?->getReasoning() ?? [] as $reasoning) {
            $signature = (string)$reasoning->getSignature();
            if ($signature === '') {
                continue;
            }
            $content[] = ['type' => 'thinking', 'thinking' => $reasoning->getText(), 'signature' => $signature];
        }
        if ($this->text !== '') {
            $content[] = ['type' => 'text', 'text' => $this->text];
        }
        foreach ($this->toolUses as $toolUse) {
            $content[] = ['type' => 'tool_use'] + $toolUse;
        }
        return $content !== [] ? ['role' => 'assistant', 'content' => $content] : null;
    }

    public function toolUses(): array
    {
        return $this->toolUses;
    }

    public function usage(): array
    {
        $usage = $this->tokenUsage();
        $read = $usage?->getCacheReadTokens() ?? 0;
        $write = $usage?->getCacheWriteTokens() ?? 0;
        return [
            'input_tokens' => max(0, $this->promptTokens() - $read - $write),
            'output_tokens' => $usage?->getCompletionTokens() ?? 0,
            'cache_read_input_tokens' => $read,
            'cache_creation_input_tokens' => $write,
        ];
    }

    public function promptTokens(): int
    {
        return $this->tokenUsage()?->getPromptTokens() ?? 0;
    }

    public function stopReason(): ?string
    {
        if ($this->response === null) {
            return null;
        }
        return match ($this->response->getFinishReason()) {
            FinishReason::Length => 'max_tokens',
            FinishReason::ContentFilter => 'refusal',
            FinishReason::ToolCall => 'tool_use',
            FinishReason::Stop, FinishReason::StopSequence => 'end_turn',
            default => ($this->toolUses !== [] || $this->response->hasToolCalls()) ? 'tool_use' : 'end_turn',
        };
    }

    private function tokenUsage(): ?TokenUsageInterface
    {
        return $this->response?->getUsage() ?? $this->chunkUsage;
    }

    private function flushNote(): Generator
    {
        $note = trim($this->note);
        $this->note = '';
        if ($this->isNote($note)) {
            yield new ProgressNote($note);
        }
    }

    private function isNote(string $text): bool
    {
        $text = trim($text);

        return $text !== '' && $text !== self::INTERRUPTED_NOTE;
    }
}
