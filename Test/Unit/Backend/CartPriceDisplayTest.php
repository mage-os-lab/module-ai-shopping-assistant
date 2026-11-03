<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Framework\App\Config\ScopeConfigInterface;
use MageOS\AiShoppingAssistant\Model\Backend\CartPriceDisplay;
use PHPUnit\Framework\TestCase;

class CartPriceDisplayTest extends TestCase
{
    private function display(string $mode, string $storeCartDisplay): CartPriceDisplay
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => match ($path) {
                'ai_integration/aiagent/content/cart_prices' => $mode,
                'tax/cart_display/price' => $storeCartDisplay,
                default => null,
            }
        );

        return new CartPriceDisplay($scopeConfig);
    }

    public function testAnExplicitModeWinsOverTheStoreCartDisplay(): void
    {
        $this->assertTrue($this->display('incl', '1')->includesTax(1));
        $this->assertFalse($this->display('excl', '2')->includesTax(1));
    }

    public function testTheStoreModeFollowsTheShoppingCartDisplaySetting(): void
    {
        $this->assertTrue($this->display('store', '2')->includesTax(1));
        $this->assertFalse($this->display('store', '1')->includesTax(1));
        $this->assertFalse($this->display('store', '3')->includesTax(1));
    }
}
