<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Session;

use MageOS\AiShoppingAssistant\Api\Data\PageContextInterface;
use MageOS\AiShoppingAssistant\Api\Presentation\PresentationExtensionInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\PresentationRefused;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Checkout;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Comparison;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\OrderStatus;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Products;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Enrich\Suggestions;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\EnrichmentContext;
use MageOS\AiShoppingAssistant\Model\Agent\Presentation\Registry;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\DynamicContext;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\NameMatch;
use MageOS\AiShoppingAssistant\Model\Agent\Prompt\PageNote;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Session\TranscriptView;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class TranscriptViewTest extends TestCase
{
    private function registry(array $extensions = []): Registry
    {
        $sanitizer = new Sanitizer();
        $fence = new Fence($sanitizer);
        $serializer = new Serializer($fence);
        return new Registry(
            new Products($this->createMock(LoggerInterface::class), $sanitizer),
            new Comparison(),
            new OrderStatus($serializer, $this->createMock(\MageOS\AiShoppingAssistant\Api\Backend\GuestOrderLookupInterface::class)),
            new Checkout($serializer, $this->createMock(\Magento\Framework\UrlInterface::class)),
            new Suggestions($sanitizer),
            $extensions
        );
    }

    private function ctx(SessionState $state): EnrichmentContext
    {
        $page = $this->createMock(PageContextInterface::class);
        $context = new SessionContext('sess-1', null, 1, 1, $page, new \DateTimeImmutable('now'));
        return new EnrichmentContext($this->createMock(StorefrontBackendInterface::class), new AgentConfig(), $context, $state);
    }

    private function stateWithSeenProducts(array $records): SessionState
    {
        $state = new SessionState();
        $state->rememberProducts($records);
        return $state;
    }

    public function testUserTextBlockBecomesUserEntry(): void
    {
        $rows = [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello there']]],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([['role' => 'user', 'text' => 'Hello there']], $messages);
    }

    public function testUserPageNoteBlockIsHiddenFromTheRestoredCustomerMessage(): void
    {
        $rows = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => PageNote::PREFIX . 'the customer is now on the cart page.]'],
                    ['type' => 'text', 'text' => 'Is this one better for a gift?'],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([['role' => 'user', 'text' => 'Is this one better for a gift?']], $messages);
    }

    public function testTheSessionContextBlockIsHiddenFromTheRestoredCustomerMessage(): void
    {
        $rows = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => DynamicContext::PREFIX . "\n\n<storefront_data>{}</storefront_data>"],
                    ['type' => 'text', 'text' => PageNote::PREFIX . 'the customer is now on the cart page.]'],
                    ['type' => 'text', 'text' => 'Show me some backpacks'],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([['role' => 'user', 'text' => 'Show me some backpacks']], $messages);
    }

    public function testTheNameMatchBlockIsHiddenFromTheRestoredCustomerMessage(): void
    {
        $rows = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => PageNote::PREFIX . 'the customer is now on the cart page.]'],
                    ['type' => 'text', 'text' => NameMatch::PREFIX . 'products whose names contain every word the customer typed: Neat Chair (product_id 1).]'],
                    ['type' => 'text', 'text' => 'neat chair'],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([['role' => 'user', 'text' => 'neat chair']], $messages);
    }

    public function testUserMessageThatIsOnlyAPageNoteIsNotEmittedAtAll(): void
    {
        $rows = [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => PageNote::PREFIX . 'the customer is now on the cart page.]'],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([], $messages);
    }

    public function testAssistantTextBlockBecomesAssistantEntryWithEmptyCards(): void
    {
        $rows = [
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Hi, how can I help?']]],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame(
            [['role' => 'assistant', 'text' => 'Hi, how can I help?', 'cards' => []]],
            $messages
        );
    }

    public function testTextBlocksAroundAToolCallAreSeparateParagraphs(): void
    {
        $rows = [
            ['role' => 'assistant', 'content' => [
                ['type' => 'text', 'text' => 'Here are three picks.'],
                ['type' => 'tool_use', 'id' => 'tu1', 'name' => 'lookup', 'input' => []],
                ['type' => 'text', 'text' => '- **Decanter**: for hosts.'],
            ]],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame("Here are three picks.\n\n- **Decanter**: for hosts.", $messages[0]['text']);
    }

    public function testToolResultOnlyUserMessageIsNotEmittedAsAUserEntry(): void
    {
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search_products', 'input' => []],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'ok', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([], $messages);
    }

    public function testPresentationToolUseJoinedWithToolResultBecomesACard(): void
    {
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
        ]);
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Here is a pick.'],
                    [
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'present_products',
                        'input' => ['picks' => [['product_id' => 'p-100']]],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'Displayed.', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx($state));
        $this->assertCount(1, $messages);
        $this->assertSame('assistant', $messages[0]['role']);
        $this->assertSame('Here is a pick.', $messages[0]['text']);
        $this->assertCount(1, $messages[0]['cards']);
        $this->assertSame('products', $messages[0]['cards'][0]['component']);
        $this->assertSame('tu_1', $messages[0]['cards'][0]['id']);
        $this->assertSame('p-100', $messages[0]['cards'][0]['payload']['items'][0]['product']['product_id']);
    }

    public function testToolUseOnlyWithNoTextStillYieldsAnAssistantEntryWhenItHasCards(): void
    {
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
        ]);
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'present_products',
                        'input' => ['picks' => [['product_id' => 'p-100']]],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'Displayed.', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx($state));
        $this->assertCount(1, $messages);
        $this->assertSame('', $messages[0]['text']);
        $this->assertCount(1, $messages[0]['cards']);
    }

    public function testToolUseWithNoTextAndNoCardIsSkippedEntirely(): void
    {
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'search_products', 'input' => ['query' => 'tent']],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'ok', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([], $messages);
    }

    public function testErroredToolResultDoesNotBecomeACard(): void
    {
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
        ]);
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Let me try that.'],
                    [
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'present_products',
                        'input' => ['picks' => [['product_id' => 'p-100']]],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'refused', 'is_error' => true],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx($state));
        $this->assertCount(1, $messages);
        $this->assertSame([], $messages[0]['cards']);
    }

    public function testPayloadIsReEnrichedFromCurrentSeenProductsSoPricesAreCurrent(): void
    {
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 199.0, 'currency' => 'USD'],
        ]);
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'present_products',
                        'input' => ['picks' => [['product_id' => 'p-100']]],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'Displayed.', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx($state));
        $this->assertSame(199.0, $messages[0]['cards'][0]['payload']['items'][0]['product']['price']);
    }

    public function testProductNoLongerInSeenProductsDropsTheCard(): void
    {
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Here you go.'],
                    [
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'present_products',
                        'input' => ['picks' => [['product_id' => 'ghost-1']]],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'Displayed.', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx(new SessionState()));
        $this->assertSame([], $messages[0]['cards']);
    }

    public function testComparisonCardCarriesAPriceDelta(): void
    {
        $state = $this->stateWithSeenProducts([
            ['product_id' => 'p-100', 'title' => 'Tent', 'price' => 149.0, 'currency' => 'USD'],
            ['product_id' => 'p-200', 'title' => 'Stove', 'price' => 89.0, 'currency' => 'USD'],
        ]);
        $rows = [
            [
                'role' => 'assistant',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'present_comparison',
                        'input' => ['entries' => [['product_id' => 'p-100'], ['product_id' => 'p-200']]],
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'Displayed.', 'is_error' => false],
                ],
            ],
        ];
        $messages = (new TranscriptView())->render($rows, $this->registry(), $this->ctx($state));
        $this->assertSame('comparison', $messages[0]['cards'][0]['component']);
        $this->assertSame(60.0, $messages[0]['cards'][0]['payload']['price_delta']['amount']);
    }

    public function testAnExtensionCardIsRebuiltByItsEnricherOnRestore(): void
    {
        $messages = (new TranscriptView())->render(
            $this->extensionRows(['sku' => 'mug']),
            $this->registry([$this->extension(fn (array $input) => ['sku' => $input['sku'], 'image' => 'mug.jpg'])]),
            $this->ctx(new SessionState())
        );

        $this->assertSame(
            [['component' => 'swatches', 'payload' => ['sku' => 'mug', 'image' => 'mug.jpg'], 'id' => 'tu_1']],
            $messages[0]['cards']
        );
    }

    public function testAnExtensionCardItsEnricherRefusesIsLeftOutOnRestore(): void
    {
        $refuse = function (): array {
            throw new PresentationRefused('gone');
        };
        $messages = (new TranscriptView())->render(
            $this->extensionRows(['sku' => 'mug']),
            $this->registry([$this->extension($refuse)]),
            $this->ctx(new SessionState())
        );

        $this->assertSame([], $messages[0]['cards']);
    }

    private function extensionRows(array $input): array
    {
        return [
            [
                'role' => 'assistant',
                'content' => [
                    ['type' => 'text', 'text' => 'Here they are.'],
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'present_swatches', 'input' => $input],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => 'ok', 'is_error' => false],
                ],
            ],
        ];
    }

    private function extension(callable $enrich): PresentationExtensionInterface
    {
        $extension = $this->createMock(PresentationExtensionInterface::class);
        $extension->method('getToolName')->willReturn('present_swatches');
        $extension->method('getComponent')->willReturn('swatches');
        $extension->method('getInputSchema')->willReturn(['type' => 'object']);
        $extension->method('getDescription')->willReturn('Swatches');
        $extension->method('getTemplate')->willReturn('Vendor_Store::cards/swatches.phtml');
        $extension->method('enrich')->willReturnCallback($enrich);
        return $extension;
    }
}
