<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Store\Model\ScopeInterface;
use MageOS\AiShoppingAssistant\Model\Config\Source\CartPrices;

/**
 * Decides whether cart line prices reach the assistant with tax or without it. By default it follows the
 * store's own Shopping Cart Display Settings, so the assistant quotes the prices the customer sees in the cart.
 */
class CartPriceDisplay
{
    private const PATH_MODE = 'ai_integration/aiagent/content/cart_prices';
    private const PATH_TAX_CART_PRICE = 'tax/cart_display/price';
    private const TAX_DISPLAY_INCLUDING = 2;

    public function __construct(
        private readonly \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
    }

    public function includesTax(int $storeId): bool
    {
        $mode = (string)$this->scopeConfig->getValue(self::PATH_MODE, ScopeInterface::SCOPE_STORE, $storeId);
        if ($mode === CartPrices::INCLUDING_TAX) {
            return true;
        }
        if ($mode === CartPrices::EXCLUDING_TAX) {
            return false;
        }

        return (int)$this->scopeConfig->getValue(self::PATH_TAX_CART_PRICE, ScopeInterface::SCOPE_STORE, $storeId)
            === self::TAX_DISPLAY_INCLUDING;
    }
}
