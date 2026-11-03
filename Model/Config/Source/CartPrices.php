<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class CartPrices implements OptionSourceInterface
{
    public const STORE = 'store';
    public const INCLUDING_TAX = 'incl';
    public const EXCLUDING_TAX = 'excl';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::STORE, 'label' => __('As the Store Shows the Cart')],
            ['value' => self::INCLUDING_TAX, 'label' => __('Including Tax')],
            ['value' => self::EXCLUDING_TAX, 'label' => __('Excluding Tax')],
        ];
    }
}
