<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Data;

use MageOS\AiShoppingAssistant\Model\Data\Product;
use MageOS\AiShoppingAssistant\Model\Data\ProductDetails;
use PHPUnit\Framework\TestCase;

class ProductDetailsTest extends TestCase
{
    public function testAnUnknownStockSurvivesTheDetailsRecord(): void
    {
        $details = ProductDetails::fromProduct(
            Product::fromArray(['product_id' => 'P-1', 'title' => 'Pendant', 'price' => 660.0, 'in_stock' => null]),
            null,
            [],
            []
        );

        $this->assertFalse($details->isStockKnown());
        $this->assertTrue($details->isInStock());
        $this->assertNull($details->toArray()['in_stock']);
    }

    public function testAKnownStockStaysKnownAndCanBeReplaced(): void
    {
        $details = ProductDetails::fromProduct(
            Product::fromArray(['product_id' => 'P-2', 'title' => 'Chair', 'price' => 900.0, 'in_stock' => false]),
            null,
            [],
            []
        );

        $this->assertFalse($details->isInStock());
        $this->assertTrue($details->isStockKnown());
        $this->assertFalse($details->withInStock(null)->isStockKnown());
        $this->assertTrue($details->withInStock(true)->isInStock());
    }
}
