<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit;

use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence;
use MageOS\AiShoppingAssistant\Model\Agent\Fencing\Sanitizer;
use MageOS\AiShoppingAssistant\Model\Agent\Serializer;
use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use MageOS\AiShoppingAssistant\Model\Data\FulfillmentOption;
use MageOS\AiShoppingAssistant\Model\Data\Order;
use MageOS\AiShoppingAssistant\Model\Data\OrderItem;
use MageOS\AiShoppingAssistant\Model\Data\Policy;
use MageOS\AiShoppingAssistant\Model\Data\Product;
use MageOS\AiShoppingAssistant\Model\Data\ProductDetails;
use PHPUnit\Framework\TestCase;

class SerializerTest extends TestCase
{
    private Serializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new Serializer(new Fence(new Sanitizer()));
    }

    public function testCompactProductCarriesAttributes(): void
    {
        $product = Product::fromArray([
            'product_id' => 'AR-0001',
            'title' => 'Trailhead Anorak',
            'price' => 89.0,
            'attributes' => ['color' => 'moss green', 'fabric' => 'recycled ripstop'],
        ]);
        $compact = $this->serializer->compactProduct($product->toArray());
        $this->assertSame(['color' => 'moss green', 'fabric' => 'recycled ripstop'], $compact['attributes']);
    }

    public function testCompactProductOmitsEmptyOptionals(): void
    {
        $product = Product::fromArray(['product_id' => 'AR-0002', 'title' => 'Camp Mug', 'price' => 9.0]);
        $compact = $this->serializer->compactProduct($product->toArray());
        foreach (
            ['attributes', 'labels', 'brand', 'rating', 'options', 'option_values', 'variant_of', 'custom_options',
                'original_price']
            as $absent
        ) {
            $this->assertArrayNotHasKey($absent, $compact);
        }
    }

    public function testCompactProductCarriesOriginalPriceWhenTheProductIsOnSale(): void
    {
        $product = Product::fromArray([
            'product_id' => 'AR-0095',
            'title' => 'Clearance Lamp',
            'price' => 119.4,
            'original_price' => 140.5,
        ]);
        $compact = $this->serializer->compactProduct($product->toArray());
        $this->assertSame(140.5, $compact['original_price']);
    }

    public function testCompactProductAddsNeedsPageChoicesForRequiredCustomOptions(): void
    {
        $product = Product::fromArray([
            'product_id' => 'AR-0009',
            'title' => 'Engraved Mug',
            'price' => 25.0,
            'has_required_custom_options' => true,
        ]);
        $compact = $this->serializer->compactProduct($product->toArray());
        $this->assertTrue($compact['needs_page_choices']);
    }

    public function testCompactProductEmitsCustomOptionsWithoutIdsAndWithConditionalPrice(): void
    {
        $product = Product::fromArray([
            'product_id' => 'AR-0090',
            'title' => 'Alessi 9090 Espresso Maker',
            'price' => 175.0,
            'has_required_custom_options' => true,
            'custom_options' => [[
                'option_id' => 7,
                'title' => 'Size',
                'type' => 'drop_down',
                'required' => true,
                'values' => [
                    ['value_id' => 1, 'title' => '1 CUP', 'price' => 0.0, 'price_type' => 'fixed'],
                    ['value_id' => 2, 'title' => '3 CUP', 'price' => 15.0, 'price_type' => 'fixed'],
                ],
            ]],
        ]);
        $compact = $this->serializer->compactProduct($product->toArray());
        $this->assertSame(
            [['title' => 'Size', 'required' => true, 'values' => ['1 CUP', '3 CUP (+15.00)']]],
            $compact['custom_options']
        );
        $this->assertArrayNotHasKey('option_id', $compact['custom_options'][0]);
        $this->assertIsString($compact['custom_options'][0]['values'][0]);
    }

    public function testNeedsPageChoicesIsAbsentWhenEveryRequiredCustomOptionIsSettableInChat(): void
    {
        $product = Product::fromArray([
            'product_id' => 'AR-0091',
            'title' => 'Alessi 9090 Espresso Maker',
            'price' => 175.0,
            'has_required_custom_options' => true,
            'custom_options' => [[
                'option_id' => 7,
                'title' => 'Size',
                'type' => 'drop_down',
                'required' => true,
                'values' => [['value_id' => 1, 'title' => '1 CUP', 'price' => 0.0, 'price_type' => 'fixed']],
            ]],
        ]);
        $compact = $this->serializer->compactProduct($product->toArray());
        $this->assertArrayNotHasKey('needs_page_choices', $compact);
    }

    public function testNeedsPageChoicesIsTrueWhenARequiredCustomOptionTypeIsNotSettableInChat(): void
    {
        $product = Product::fromArray([
            'product_id' => 'AR-0092',
            'title' => 'Engraved Frame',
            'price' => 40.0,
            'has_required_custom_options' => true,
            'custom_options' => [[
                'option_id' => 3,
                'title' => 'Delivery Date',
                'type' => 'date',
                'required' => true,
                'values' => [],
            ]],
        ]);
        $compact = $this->serializer->compactProduct($product->toArray());
        $this->assertTrue($compact['needs_page_choices']);
    }

    public function testAFamilyRecordCarriesItsOptionsAndItsVariantsTheirOptionValues(): void
    {
        $family = ProductDetails::fromProduct(
            Product::fromArray([
                'product_id' => 'AR-0003',
                'title' => 'Trail Pad',
                'price' => 59.0,
                'options' => ['length' => ['regular', 'long']],
            ]),
            null,
            [],
            [Product::fromArray([
                'product_id' => 'AR-0003-L',
                'title' => 'Trail Pad',
                'price' => 69.0,
                'option_values' => ['length' => 'long'],
                'variant_of' => 'AR-0003',
            ])]
        );
        $payload = $this->serializer->productDetails($family);
        $this->assertSame(['length' => ['regular', 'long']], $payload['options']);
        $this->assertCount(1, $payload['variants']);
        $this->assertEquals(
            ['product_id' => 'AR-0003-L', 'option_values' => ['length' => 'long'], 'price' => 69.0, 'in_stock' => true],
            $payload['variants'][0]
        );
        $alone = $this->serializer->compactProduct($family->getVariants()[0]->toArray());
        $this->assertSame('Trail Pad', $alone['title']);
        $this->assertSame('AR-0003', $alone['variant_of']);
    }

    public function testProductDetailsEmitsNoteWhenSet(): void
    {
        $family = ProductDetails::fromProduct(
            Product::fromArray(['product_id' => 'AR-0020', 'title' => 'Trail Pad', 'price' => 59.0]),
            null,
            [],
            [],
            'More variants exist; ask about a size or colour to narrow the list.'
        );
        $payload = $this->serializer->productDetails($family);
        $this->assertSame(
            'More variants exist; ask about a size or colour to narrow the list.',
            $payload['note']
        );
    }

    public function testProductDetailsOmitsNoteWhenAbsent(): void
    {
        $family = ProductDetails::fromProduct(
            Product::fromArray(['product_id' => 'AR-0021', 'title' => 'Trail Pad', 'price' => 59.0]),
            null,
            [],
            []
        );
        $payload = $this->serializer->productDetails($family);
        $this->assertArrayNotHasKey('note', $payload);
    }

    public function testVariantRowKeepsOnlyAttributesThatDifferFromTheFamily(): void
    {
        $family = $this->serializer->compactProduct(Product::fromArray([
            'product_id' => 'AR-0010',
            'title' => 'Pad',
            'price' => 59.0,
            'attributes' => ['fabric' => 'ripstop'],
        ])->toArray());
        $variant = Product::fromArray([
            'product_id' => 'AR-0010-L',
            'title' => 'Pad',
            'price' => 69.0,
            'attributes' => ['fabric' => 'ripstop', 'color' => 'moss'],
            'option_values' => ['length' => 'long'],
            'variant_of' => 'AR-0010',
        ]);
        $row = $this->serializer->variantRow($variant->toArray(), $family);
        $this->assertSame(['color' => 'moss'], $row['attributes']);
        $this->assertArrayNotHasKey('variant_of', $row);
    }

    public function testVariantRowAlwaysCarriesItsOwnOriginalPriceEvenWhenTheFamilyMatches(): void
    {
        $family = $this->serializer->compactProduct(Product::fromArray([
            'product_id' => 'AR-0011',
            'title' => 'Clearance Pad',
            'price' => 59.0,
            'original_price' => 79.0,
        ])->toArray());
        $variant = Product::fromArray([
            'product_id' => 'AR-0011-L',
            'title' => 'Clearance Pad',
            'price' => 59.0,
            'original_price' => 79.0,
            'option_values' => ['length' => 'long'],
            'variant_of' => 'AR-0011',
        ]);
        $row = $this->serializer->variantRow($variant->toArray(), $family);
        $this->assertSame(79.0, $row['original_price']);
    }

    public function testCartAndOrderLinesCarryOptionKeysOnlyForVariants(): void
    {
        $plain = new CartItem('AR-0002', 'Camp Mug', 9.0, 1);
        $chosen = new CartItem('AR-0003-L', 'Trail Pad', 69.0, 1, null, ['length' => 'long'], 'AR-0003');
        $lines = $this->serializer->cart(new Cart([$plain, $chosen]))['items'];
        $this->assertArrayNotHasKey('option_values', $lines[0]);
        $this->assertArrayNotHasKey('variant_of', $lines[0]);
        $this->assertSame(['length' => 'long'], $lines[1]['option_values']);
        $this->assertSame('AR-0003', $lines[1]['variant_of']);

        $order = new Order('o-1', 'delivered', new \DateTimeImmutable('2026-06-01'), 78.0, 'USD', [
            new OrderItem('AR-0002', 'Camp Mug', 1, 9.0),
            new OrderItem('AR-0003-L', 'Trail Pad', 1, 69.0, ['length' => 'long'], 'AR-0003'),
        ]);
        $items = $this->serializer->order($order)['items'];
        $this->assertArrayNotHasKey('option_values', $items[0]);
        $this->assertSame(['length' => 'long'], $items[1]['option_values']);
        $this->assertSame('AR-0003', $items[1]['variant_of']);
    }

    public function testOrderAgeIsComputedFromTheTurnClock(): void
    {
        $order = Order::fromArray([
            'order_id' => '1000077639',
            'status' => 'processing',
            'placed_at' => '2026-10-12T18:11:36+00:00',
            'total' => 500.0,
            'currency' => 'CAD',
            'items' => [],
        ]);

        $aged = $this->serializer->order($order, new \DateTimeImmutable('2026-10-15T16:02:42+00:00'));
        $this->assertSame(69, $aged['placed_hours_ago']);
        $this->assertSame(2, $aged['placed_days_ago']);

        $this->assertArrayNotHasKey('placed_hours_ago', $this->serializer->order($order));
        $this->assertSame(
            69,
            $this->serializer->orders([$order], new \DateTimeImmutable('2026-10-15T16:02:42+00:00'))[0]['placed_hours_ago']
        );
    }

    public function testOrderAgeIsNeverNegative(): void
    {
        $order = Order::fromArray([
            'order_id' => 'o-1',
            'status' => 'pending',
            'placed_at' => '2026-10-15T17:00:00+00:00',
            'total' => 1.0,
            'currency' => 'CAD',
            'items' => [],
        ]);

        $aged = $this->serializer->order($order, new \DateTimeImmutable('2026-10-15T16:00:00+00:00'));
        $this->assertSame(0, $aged['placed_hours_ago']);
    }

    public function testOrdersReturnsANoteWhenEmpty(): void
    {
        $this->assertSame(['note' => 'No orders found.'], $this->serializer->orders([]));
    }

    public function testPoliciesReturnsANoteWhenEmpty(): void
    {
        $this->assertSame(['note' => 'No matching policy content.'], $this->serializer->policies([]));
    }

    public function testPoliciesDropCategoryWhenAbsent(): void
    {
        $policy = new Policy('pol-1', 'Returns', '30 days, unworn.');
        $result = $this->serializer->policies([$policy]);
        $this->assertArrayNotHasKey('category', $result[0]);
        $this->assertSame('30 days, unworn.', $result[0]['content']);
    }

    public function testFulfillmentReturnsANoteWhenEmpty(): void
    {
        $this->assertSame(['note' => 'No fulfillment options available.'], $this->serializer->fulfillment([]));
    }

    public function testFulfillmentDropsLocationWhenAbsent(): void
    {
        $option = new FulfillmentOption('delivery', '2-3 days', 4.99);
        $result = $this->serializer->fulfillment([$option]);
        $this->assertArrayNotHasKey('location', $result[0]);
        $this->assertSame('delivery', $result[0]['method']);
    }

    public function testSearchResultTextHeaderForZeroResults(): void
    {
        $header = 'Search returned 0 results: nothing in the catalog matched this query. '
            . 'Run the broader retry before telling the customer it is not carried, and do not present a '
            . 'different product as the requested one. Search matches product text, not ids; resolve a '
            . 'product id with get_product_details.';
        $text = $this->serializer->searchResultText('nothing here', [], 12000);
        $this->assertStringStartsWith($header, $text);
        $this->assertStringContainsString('"result_count":0', $text);
    }

    public function testSearchResultTextHeaderForNResults(): void
    {
        $products = [Product::fromArray([
            'product_id' => 'p-1',
            'title' => 'Mug',
            'price' => 5.0,
            'currency' => 'USD',
            'in_stock' => true,
        ])->toArray()];
        $text = $this->serializer->searchResultText('mug', $products, 12000);
        $this->assertStringStartsWith("Search returned 1 result(s): the catalog's closest text matches,", $text);
        $this->assertStringContainsString('p-1', $text);
        $this->assertStringStartsWith('<' . Fence::LABEL . '>', mb_substr($text, mb_strpos($text, "\n") + 1));
    }

    public function testCategorySearchTextHeaderForZeroResults(): void
    {
        $text = $this->serializer->categorySearchText('board game', [], 12000);
        $this->assertStringStartsWith(
            'No category name matches these keywords. If a product search for the same thing '
                . 'also finds nothing that fits, the store does not carry it; say so and offer catalog '
                . 'map entries that suit the stated purpose.',
            $text
        );
        $this->assertStringContainsString('"result_count":0', $text);
    }

    public function testCategorySearchTextHeaderForNResults(): void
    {
        $results = [['category_id' => 1235, 'name' => 'Lounge Chairs', 'path' => 'Seating > Lounge Chairs', 'product_count' => 12]];
        $text = $this->serializer->categorySearchText('lounge chairs', $results, 12000);
        $this->assertStringStartsWith(
            'Category search returned 1 match(es); pass a category_id to search_products to list '
                . 'or search inside one.',
            $text
        );
        $this->assertStringContainsString('1235', $text);
    }

    public function testCartPayloadShape(): void
    {
        $item = new CartItem('p-1', 'Mug', 5.0, 2);
        $cart = new Cart([$item], 'USD');
        $payload = $this->serializer->cart($cart);
        $this->assertSame(2, $payload['item_count']);
        $this->assertSame(10.0, $payload['subtotal']);
        $this->assertSame('USD', $payload['currency']);
        $this->assertSame('p-1', $payload['items'][0]['product_id']);
        $this->assertSame(10.0, $payload['items'][0]['line_total']);
    }

    public function testCartSummaryFormatsAsExpected(): void
    {
        $item = new CartItem('p-1', 'Mug', 5.0, 2);
        $cart = new Cart([$item], 'USD');
        $this->assertSame('2 item(s), subtotal 10.00 USD', $this->serializer->cartSummary($cart));
    }

    public function testCartSummaryAddsTheDiscountAndCode(): void
    {
        $cart = new Cart([new CartItem('p-1', 'Chair', 2395.0, 1)], 'CAD', 359.25, 'SAVE15');
        $this->assertSame(
            '1 item(s), subtotal 2395.00 CAD, discount 359.25 (code SAVE15), after discount 2035.75 CAD '
            . 'before shipping and tax',
            $this->serializer->cartSummary($cart)
        );
    }

    public function testCartSummaryOfAnAutomaticDiscountNamesNoCode(): void
    {
        $cart = new Cart([new CartItem('p-1', 'Chair', 2395.0, 1)], 'CAD', 359.25);
        $this->assertSame(
            '1 item(s), subtotal 2395.00 CAD, discount 359.25, after discount 2035.75 CAD before shipping and tax',
            $this->serializer->cartSummary($cart)
        );
    }

    public function testCartPayloadCarriesTheDiscountOnlyWhenThereIsOne(): void
    {
        $plain = $this->serializer->cart(new Cart([new CartItem('p-1', 'Chair', 2395.0, 1)], 'CAD'));
        $this->assertArrayNotHasKey('discount', $plain);
        $this->assertArrayNotHasKey('coupon_code', $plain);

        $discounted = $this->serializer->cart(new Cart([new CartItem('p-1', 'Chair', 2395.0, 1)], 'CAD', 359.25, 'SAVE15'));
        $this->assertSame(359.25, $discounted['discount']);
        $this->assertSame(2035.75, $discounted['subtotal_after_discount']);
        $this->assertSame('SAVE15', $discounted['coupon_code']);
        $this->assertSame('not included, added at checkout', $discounted['shipping_and_tax']);
    }

    public function testCompactProductLeavesOutAStockItDoesNotKnow(): void
    {
        $record = ['product_id' => 'P-1', 'title' => 'Pendant', 'price' => 660.0];

        $this->assertArrayNotHasKey('in_stock', $this->serializer->compactProduct($record + ['in_stock' => null]));
        $this->assertFalse($this->serializer->compactProduct($record + ['in_stock' => false])['in_stock']);
        $this->assertTrue($this->serializer->compactProduct($record)['in_stock']);
    }

    public function testProductDetailsPutTheCustomOptionsAfterTheSpecs(): void
    {
        $details = ProductDetails::fromProduct(
            Product::fromArray([
                'product_id' => 'SOFA-1',
                'title' => 'Sofa',
                'price' => 4000.0,
                'custom_options' => [['title' => 'Fabric', 'type' => 'drop_down', 'required' => true, 'values' => [['title' => 'Linen', 'price' => 0.0]]]],
            ]),
            null,
            ['Width' => '240 cm'],
            []
        );

        $keys = array_keys($this->serializer->productDetails($details, 12000));

        $this->assertGreaterThan(array_search('specs', $keys, true), array_search('custom_options', $keys, true));
    }

    public function testProductDetailsShortenLongOptionListsSoTheSpecsFit(): void
    {
        $values = [];
        foreach (range(1, 2000) as $n) {
            $values[] = ['title' => 'Fabric Collection Category P Colour ' . $n, 'price' => 120.0];
        }
        $details = ProductDetails::fromProduct(
            Product::fromArray([
                'product_id' => 'SOFA-2',
                'title' => 'Sofa',
                'price' => 4000.0,
                'custom_options' => [
                    ['title' => 'Fabric', 'type' => 'drop_down', 'required' => true, 'values' => $values],
                    ['title' => 'Legs', 'type' => 'drop_down', 'required' => true, 'values' => [['title' => 'Oak', 'price' => 0.0]]],
                ],
            ]),
            null,
            ['Width' => '240 cm', 'Materials' => 'Solid oak frame'],
            []
        );

        $payload = $this->serializer->productDetails($details, 12000);
        $fabric = $payload['custom_options'][0];

        $this->assertLessThanOrEqual(12000, mb_strlen((string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));
        $this->assertSame(['Width' => '240 cm', 'Materials' => 'Solid oak frame'], $payload['specs']);
        $this->assertSame('Fabric Collection Category P Colour 1 (+120.00)', $fabric['values'][0]);
        $this->assertSame(2000 - count($fabric['values']), $fabric['more_values']);
        $this->assertSame(['Oak'], $payload['custom_options'][1]['values']);
    }

    public function testProductDetailsKeepEveryOptionValueWhenTheRecordFits(): void
    {
        $details = ProductDetails::fromProduct(
            Product::fromArray([
                'product_id' => 'LAMP-1',
                'title' => 'Lamp',
                'price' => 300.0,
                'custom_options' => [['title' => 'Finish', 'type' => 'drop_down', 'required' => true, 'values' => [['title' => 'Brass', 'price' => 0.0], ['title' => 'Black', 'price' => 0.0]]]],
            ]),
            null,
            [],
            []
        );

        $option = $this->serializer->productDetails($details, 12000)['custom_options'][0];

        $this->assertSame(['Brass', 'Black'], $option['values']);
        $this->assertArrayNotHasKey('more_values', $option);
    }
}
