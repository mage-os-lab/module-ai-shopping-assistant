<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Data;

use MageOS\AiShoppingAssistant\Model\Data\Product;
use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public function testConstructorSetsValues(): void
    {
        $product = new Product(
            productId: 'SKU-1',
            title: 'Test Product',
            price: 19.99,
            originalPrice: 24.99,
            currency: 'CAD',
            brand: 'Acme',
            rating: 4.5,
            reviewCount: 10,
            imageUrl: 'https://example.test/img.jpg',
            url: 'https://example.test/product',
            category: 'Widgets',
            labels: ['new'],
            attributes: ['color' => 'red'],
            inStock: false,
            shortDescription: 'A short description',
            options: ['Size' => ['S', 'M']],
            optionValues: ['Size' => 'M'],
            variantOf: 'SKU-0',
            hasRequiredCustomOptions: true,
            customOptions: [['option_id' => 1, 'title' => 'Size', 'type' => 'drop_down', 'required' => true, 'values' => []]]
        );

        $this->assertSame('SKU-1', $product->getProductId());
        $this->assertSame('Test Product', $product->getTitle());
        $this->assertSame(19.99, $product->getPrice());
        $this->assertSame(24.99, $product->getOriginalPrice());
        $this->assertSame('CAD', $product->getCurrency());
        $this->assertSame('Acme', $product->getBrand());
        $this->assertSame(4.5, $product->getRating());
        $this->assertSame(10, $product->getReviewCount());
        $this->assertSame('https://example.test/img.jpg', $product->getImageUrl());
        $this->assertSame('https://example.test/product', $product->getUrl());
        $this->assertSame('Widgets', $product->getCategory());
        $this->assertSame(['new'], $product->getLabels());
        $this->assertSame(['color' => 'red'], $product->getAttributes());
        $this->assertFalse($product->isInStock());
        $this->assertSame('A short description', $product->getShortDescription());
        $this->assertSame(['Size' => ['S', 'M']], $product->getOptions());
        $this->assertSame(['Size' => 'M'], $product->getOptionValues());
        $this->assertSame('SKU-0', $product->getVariantOf());
        $this->assertTrue($product->hasRequiredCustomOptions());
        $this->assertSame(
            [['option_id' => 1, 'title' => 'Size', 'type' => 'drop_down', 'required' => true, 'values' => []]],
            $product->getCustomOptions()
        );
    }

    public function testDefaults(): void
    {
        $product = new Product(productId: 'SKU-2', title: 'Minimal', price: 5.0);

        $this->assertSame('USD', $product->getCurrency());
        $this->assertNull($product->getOriginalPrice());
        $this->assertNull($product->getBrand());
        $this->assertNull($product->getRating());
        $this->assertNull($product->getReviewCount());
        $this->assertNull($product->getImageUrl());
        $this->assertNull($product->getUrl());
        $this->assertNull($product->getCategory());
        $this->assertSame([], $product->getLabels());
        $this->assertSame([], $product->getAttributes());
        $this->assertTrue($product->isInStock());
        $this->assertNull($product->getShortDescription());
        $this->assertSame([], $product->getOptions());
        $this->assertSame([], $product->getOptionValues());
        $this->assertNull($product->getVariantOf());
        $this->assertFalse($product->hasRequiredCustomOptions());
        $this->assertSame([], $product->getCustomOptions());
    }

    public function testFromArray(): void
    {
        $data = [
            'product_id' => 'SKU-3',
            'title' => 'From Array Product',
            'brand' => 'Acme',
            'price' => 42.5,
            'original_price' => 49.5,
            'currency' => 'USD',
            'rating' => 4.0,
            'review_count' => 3,
            'image_url' => 'https://example.test/a.jpg',
            'url' => 'https://example.test/a',
            'category' => 'Gadgets',
            'labels' => ['sale'],
            'attributes' => ['material' => 'steel'],
            'in_stock' => true,
            'short_description' => 'Nice',
            'options' => [],
            'option_values' => [],
            'variant_of' => null,
            'has_required_custom_options' => false,
        ];

        $product = Product::fromArray($data);

        $this->assertSame('SKU-3', $product->getProductId());
        $this->assertSame('From Array Product', $product->getTitle());
        $this->assertSame(42.5, $product->getPrice());
        $this->assertSame(49.5, $product->getOriginalPrice());
        $this->assertSame(3, $product->getReviewCount());
        $this->assertSame(['sale'], $product->getLabels());
        $this->assertSame(['material' => 'steel'], $product->getAttributes());
    }

    public function testToArrayRoundTrip(): void
    {
        $data = [
            'product_id' => 'SKU-4',
            'title' => 'Round Trip',
            'brand' => null,
            'price' => 9.99,
            'original_price' => null,
            'currency' => 'USD',
            'rating' => null,
            'review_count' => null,
            'image_url' => null,
            'url' => null,
            'category' => null,
            'labels' => [],
            'attributes' => [],
            'in_stock' => true,
            'short_description' => null,
            'options' => [],
            'option_values' => [],
            'variant_of' => null,
            'has_required_custom_options' => false,
            'custom_options' => [],
        ];

        $roundTripped = Product::fromArray($data)->toArray();

        $this->assertSame($data, $roundTripped);
    }

    public function testToArrayRoundTripCarriesASetOriginalPrice(): void
    {
        $data = [
            'product_id' => 'SKU-5',
            'title' => 'On Sale',
            'brand' => null,
            'price' => 79.0,
            'original_price' => 99.0,
            'currency' => 'USD',
            'rating' => null,
            'review_count' => null,
            'image_url' => null,
            'url' => null,
            'category' => null,
            'labels' => [],
            'attributes' => [],
            'in_stock' => true,
            'short_description' => null,
            'options' => [],
            'option_values' => [],
            'variant_of' => null,
            'has_required_custom_options' => false,
            'custom_options' => [],
        ];

        $roundTripped = Product::fromArray($data)->toArray();

        $this->assertSame($data, $roundTripped);
    }

    public function testAnUnknownStockStaysUnknownAndCountsAsOrderable(): void
    {
        $unknown = Product::fromArray(['product_id' => 'SKU-9', 'title' => 'Pendant', 'price' => 660.0, 'in_stock' => null]);
        $absent = Product::fromArray(['product_id' => 'SKU-9', 'title' => 'Pendant', 'price' => 660.0]);

        $this->assertFalse($unknown->isStockKnown());
        $this->assertTrue($unknown->isInStock());
        $this->assertNull($unknown->toArray()['in_stock']);
        $this->assertTrue($absent->isStockKnown());
        $this->assertTrue($absent->isInStock());
    }
}
