<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent\Tool\Handler;

use MageOS\AiShoppingAssistant\Api\Tool\HandlerInterface;
use MageOS\AiShoppingAssistant\Model\Agent\AgentConfig;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Agent\SessionState;
use MageOS\AiShoppingAssistant\Model\Agent\ToolOutcome;
use MageOS\AiShoppingAssistant\Model\Data\Product;

class GetProductDetails implements HandlerInterface
{
    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface $backend,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Serializer $serializer,
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function handle(array $input, SessionContext $context, SessionState $state, AgentConfig $config): ToolOutcome
    {
        $productId = (string)($input['product_id'] ?? '');
        $details = $this->backend->getProductDetails($context, $productId);
        if ($details === null) {
            return ToolOutcome::error("No product with product_id {$productId}. Search for it by name instead.");
        }
        $payload = $this->serializer->productDetails($details, $config->maxFencedChars);
        $products = [Product::fromArray($details->toArray())->toArray()];
        foreach ($details->getVariants() as $variant) {
            $products[] = $variant->toArray();
        }
        $text = "Product details:\n" . $this->fence->fencePayload($payload, $config->maxFencedChars);
        return ToolOutcome::ok($text, [], $products);
    }
}
