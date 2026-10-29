<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Turn;

use Generator;
use MageOS\AiBase\Api\Data\FinishReason;
use MageOS\AiBase\Api\Data\StreamChunkType;
use MageOS\AiBase\Model\Chat\ChatResponse;
use MageOS\AiBase\Model\Chat\StreamChunk;
use MageOS\AiBase\Model\Chat\TokenUsage;
use MageOS\AiBase\Model\Chat\ToolCall;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ProgressNote;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\TextDelta;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\Item\ToolUseClosed;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StoredReasoning;
use MageOS\AiShoppingAssistant\Model\Agent\Turn\StreamedRound;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamedRoundTest extends TestCase
{
    public function testTextAndToolCallChunksBecomeItems(): void
    {
        $call = new ToolCall('tu1', 'search_products', ['query' => 'jacket']);
        $round = new StreamedRound();

        $items = iterator_to_array($round->consume($this->stream([
            new StreamChunk(StreamChunkType::Text, 'Look'),
            new StreamChunk(StreamChunkType::ToolCall, '', $call),
        ], new ChatResponse('Look', [$call]))), false);

        $this->assertEquals([new TextDelta('Look'), new ToolUseClosed('tu1', 'search_products', ['query' => 'jacket'])], $items);
    }

    public function testEveryChunkWakesTheHeartbeatButStartChunksYieldNothing(): void
    {
        $woken = 0;
        $round = new StreamedRound();

        $items = iterator_to_array($round->consume($this->stream([
            new StreamChunk(StreamChunkType::ThinkingStart),
            new StreamChunk(StreamChunkType::ToolCallStart, '', new ToolCall('tu1', 'a', [])),
            new StreamChunk(StreamChunkType::Text, 'Hi'),
        ], new ChatResponse('Hi')), static function () use (&$woken): void {
            $woken++;
        }), false);

        $this->assertSame(3, $woken);
        $this->assertEquals([new TextDelta('Hi')], $items);
    }

    public function testTheAssistantMessageOrdersSignedReasoningTextAndToolUses(): void
    {
        $call = new ToolCall('tu1', 'a', ['x' => 1]);
        $round = new StreamedRound();
        iterator_to_array($round->consume($this->stream([
            new StreamChunk(StreamChunkType::Text, 'Checking.'),
            new StreamChunk(StreamChunkType::ToolCall, '', $call),
        ], new ChatResponse('Checking.', [$call], null, FinishReason::ToolCall, 'tool_use', [
            new StoredReasoning('weighing', 'sig_1'),
            new StoredReasoning('no signature', null),
        ]))), false);

        $this->assertSame([
            'role' => 'assistant',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'weighing', 'signature' => 'sig_1'],
                ['type' => 'text', 'text' => 'Checking.'],
                ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'a', 'input' => ['x' => 1]],
            ],
        ], $round->assistantMessage());
        $this->assertSame([['id' => 'tu1', 'name' => 'a', 'input' => ['x' => 1]]], $round->toolUses());
    }

    public function testANoteIsShownWholeBeforeTheLookupItIntroduces(): void
    {
        $call = new ToolCall('tu1', 'get_product_details', ['product_id' => '42988']);
        $chunks = [
            new StreamChunk(StreamChunkType::ThinkingStart),
            new StreamChunk(StreamChunkType::Thinking, 'Pulling the table '),
            new StreamChunk(StreamChunkType::Thinking, 'details.'),
            new StreamChunk(StreamChunkType::ToolCall, '', $call),
        ];

        $shown = iterator_to_array((new StreamedRound())->consume($this->stream($chunks, new ChatResponse('', [$call])), null, true), false);
        $hidden = iterator_to_array((new StreamedRound())->consume($this->stream($chunks, new ChatResponse('', [$call]))), false);

        $this->assertEquals([new ProgressNote('Pulling the table details.'), new ToolUseClosed('tu1', 'get_product_details', ['product_id' => '42988'])], $shown);
        $this->assertEquals([new ToolUseClosed('tu1', 'get_product_details', ['product_id' => '42988'])], $hidden);
    }

    public function testTheStandInForInterruptedWorkIsNotShown(): void
    {
        $items = iterator_to_array((new StreamedRound())->consume($this->stream([
            new StreamChunk(StreamChunkType::Thinking, 'This part of the response was interrupted before it finished.'),
        ], new ChatResponse('')), null, true), false);

        $this->assertSame([], $items);
    }

    public function testUsageSplitsCacheOutOfThePromptCount(): void
    {
        $round = new StreamedRound();
        iterator_to_array($round->consume($this->stream([], new ChatResponse(
            '',
            [],
            new TokenUsage(promptTokens: 1000, completionTokens: 50, cacheReadTokens: 700, cacheWriteTokens: 200)
        ))), false);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 700,
            'cache_creation_input_tokens' => 200,
        ], $round->usage());
        $this->assertSame(1000, $round->promptTokens());
    }

    #[DataProvider('finishReasons')]
    public function testFinishReasonsMapToTheStoredStopReasons(?FinishReason $reason, bool $hasTools, string $expected): void
    {
        $calls = $hasTools ? [new ToolCall('tu1', 'a', [])] : [];
        $round = new StreamedRound();
        iterator_to_array($round->consume($this->stream([], new ChatResponse('', $calls, null, $reason))), false);

        $this->assertSame($expected, $round->stopReason());
    }

    public static function finishReasons(): array
    {
        return [
            'stop' => [FinishReason::Stop, false, 'end_turn'],
            'stop sequence' => [FinishReason::StopSequence, false, 'end_turn'],
            'tool call' => [FinishReason::ToolCall, true, 'tool_use'],
            'length' => [FinishReason::Length, false, 'max_tokens'],
            'content filter' => [FinishReason::ContentFilter, false, 'refusal'],
            'none reported with tools' => [null, true, 'tool_use'],
            'none reported without tools' => [null, false, 'end_turn'],
            'other without tools' => [FinishReason::Other, false, 'end_turn'],
        ];
    }

    public function testAFailedStreamKeepsTheTextThatAlreadyArrived(): void
    {
        $round = new StreamedRound();
        $stream = (static function (): Generator {
            yield new StreamChunk(StreamChunkType::Text, 'Half an ans');
            throw new \RuntimeException('dropped');
        })();

        try {
            iterator_to_array($round->consume($stream), false);
            $this->fail('The stream failure must reach the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('dropped', $exception->getMessage());
        }

        $this->assertSame(
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Half an ans']]],
            $round->assistantMessage()
        );
        $this->assertNull($round->stopReason());
    }

    public function testTheResponseUsageWinsOverAUsageChunk(): void
    {
        $round = new StreamedRound();
        iterator_to_array($round->consume($this->stream([
            new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(
                promptTokens: 400,
                completionTokens: 10,
                cacheReadTokens: 100,
                cacheWriteTokens: 50
            )),
        ], new ChatResponse(
            '',
            [],
            new TokenUsage(promptTokens: 1000, completionTokens: 50, cacheReadTokens: 700, cacheWriteTokens: 200)
        ))), false);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 700,
            'cache_creation_input_tokens' => 200,
        ], $round->usage());
        $this->assertSame(1000, $round->promptTokens());
    }

    public function testAFailedStreamKeepsTheUsageItReportedBeforeFailing(): void
    {
        $round = new StreamedRound();
        $stream = (static function (): Generator {
            yield new StreamChunk(StreamChunkType::Usage, '', null, new TokenUsage(
                promptTokens: 900,
                completionTokens: 30,
                cacheReadTokens: 600,
                cacheWriteTokens: 100
            ));
            throw new \RuntimeException('dropped');
        })();

        try {
            iterator_to_array($round->consume($stream), false);
            $this->fail('The stream failure must reach the caller.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('dropped', $exception->getMessage());
        }

        $this->assertSame([
            'input_tokens' => 200,
            'output_tokens' => 30,
            'cache_read_input_tokens' => 600,
            'cache_creation_input_tokens' => 100,
        ], $round->usage());
        $this->assertSame(900, $round->promptTokens());
    }

    private function stream(array $chunks, ChatResponse $response): Generator
    {
        return (static function () use ($chunks, $response): Generator {
            yield from $chunks;
            return $response;
        })();
    }
}
