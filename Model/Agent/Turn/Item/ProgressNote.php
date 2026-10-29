<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Turn\Item;

class ProgressNote
{
    public function __construct(
        public readonly string $text
    ) {
    }
}
