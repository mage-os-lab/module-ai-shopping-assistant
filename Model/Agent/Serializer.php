<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Agent;

use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\CartItemInterface;
use MageOS\AiShoppingAssistant\Api\Data\FulfillmentOptionInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderItemInterface;
use MageOS\AiShoppingAssistant\Api\Data\PolicyInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductDetailsInterface;

class Serializer
{
    private const SEARCH_EMPTY_HEADER = 'Search returned 0 results: nothing in the catalog matched this query. '
        . 'Run the broader retry before telling the customer it is not carried, and do not present a '
        . 'different product as the requested one. Search matches product text, not ids; resolve a '
        . 'product id with get_product_details.';

    private const VARIANT_ALWAYS = ['product_id', 'option_values', 'price', 'original_price', 'in_stock'];

    private const UNSETTABLE_CUSTOM_OPTION_TYPES = ['file', 'date', 'date_time', 'time'];

    private const FENCE_MARGIN = 200;

    private const STOCK_UNKNOWN = 'unknown';

    public function __construct(
        private readonly \MageOS\AiShoppingAssistant\Model\Agent\Fencing\Fence $fence
    ) {
    }

    public function compactProduct(array $record): array
    {
        $required = [
            'product_id' => (string)($record['product_id'] ?? ''),
            'title' => (string)($record['title'] ?? ''),
            'price' => (float)($record['price'] ?? 0.0),
            'currency' => (string)($record['currency'] ?? 'USD'),
        ];
        $required['in_stock'] = array_key_exists('in_stock', $record) && $record['in_stock'] === null
            ? self::STOCK_UNKNOWN
            : (bool)($record['in_stock'] ?? true);
        $optional = [
            'original_price' => $record['original_price'] ?? null,
            'brand' => $record['brand'] ?? null,
            'rating' => $record['rating'] ?? null,
            'review_count' => $record['review_count'] ?? null,
            'labels' => is_array($record['labels'] ?? null) ? $record['labels'] : [],
            'attributes' => is_array($record['attributes'] ?? null) ? $record['attributes'] : [],
            'options' => is_array($record['options'] ?? null) ? $record['options'] : [],
            'option_values' => is_array($record['option_values'] ?? null) ? $record['option_values'] : [],
            'variant_of' => $record['variant_of'] ?? null,
            'short_description' => $record['short_description'] ?? null,
        ];
        $customOptions = is_array($record['custom_options'] ?? null) ? $record['custom_options'] : [];
        if ($customOptions !== []) {
            $optional['custom_options'] = $this->customOptionsPayload($customOptions);
        }
        if ((bool)($record['has_required_custom_options'] ?? false)
            && $this->hasUnsettableRequiredCustomOption($customOptions)
        ) {
            $optional['needs_page_choices'] = true;
        }
        return $required + $this->dropEmpty($optional);
    }

    private function customOptionsPayload(array $customOptions): array
    {
        $payload = [];
        foreach ($customOptions as $option) {
            $values = [];
            foreach ((array)($option['values'] ?? []) as $value) {
                $values[] = $this->customOptionValueLabel($value);
            }
            $entry = [
                'title' => (string)($option['title'] ?? ''),
                'required' => (bool)($option['required'] ?? false),
            ];
            if ($values !== []) {
                $entry['values'] = $values;
            }
            $payload[] = $entry;
        }
        return $payload;
    }

    private function customOptionValueLabel(array $value): string
    {
        $title = (string)($value['title'] ?? '');
        $price = (float)($value['price'] ?? 0.0);
        if ($price > 0.0) {
            return sprintf('%s (+%.2f)', $title, $price);
        }
        return $title;
    }

    private function hasUnsettableRequiredCustomOption(array $customOptions): bool
    {
        if ($customOptions === []) {
            return true;
        }
        foreach ($customOptions as $option) {
            if (!(bool)($option['required'] ?? false)) {
                continue;
            }
            if (in_array((string)($option['type'] ?? ''), self::UNSETTABLE_CUSTOM_OPTION_TYPES, true)) {
                return true;
            }
        }
        return false;
    }

    public function variantRow(array $variant, array $family): array
    {
        $row = $this->compactProduct($variant);
        unset($row['variant_of']);
        $attributesDiff = $this->attributesDiff($variant, $family);
        $kept = [];
        foreach ($row as $key => $value) {
            if ($key === 'attributes') {
                continue;
            }
            if (in_array($key, self::VARIANT_ALWAYS, true)
                || !array_key_exists($key, $family)
                || $family[$key] != $value
            ) {
                $kept[$key] = $value;
            }
        }
        $lead = [
            'product_id' => $kept['product_id'],
            'option_values' => $kept['option_values'] ?? [],
        ];
        unset($kept['product_id'], $kept['option_values']);
        $result = $lead + $kept;
        if ($attributesDiff !== []) {
            $result['attributes'] = $attributesDiff;
        }
        return $result;
    }

    /**
     * Custom options go last, after the specs, and their value lists are shortened until the record fits
     * $maxChars, so a product with hundreds of fabrics keeps its measurements and materials.
     */
    public function productDetails(ProductDetailsInterface $d, int $maxChars = 0): array
    {
        $family = $this->compactProduct($d->toArray());
        $variants = [];
        foreach ($d->getVariants() as $variant) {
            $variants[] = $this->variantRow($variant->toArray(), $family);
        }
        $customOptions = $family['custom_options'] ?? [];
        unset($family['custom_options']);
        $specs = $d->getSpecs();
        $optional = $this->dropEmpty([
            'long_description' => $d->getLongDescription(),
            'specs' => is_array($specs) ? $specs : [],
            'variants' => $variants,
            'note' => $d->getNote(),
        ]);
        $payload = $family + $optional;
        if ($customOptions !== []) {
            $payload['custom_options'] = $this->fitCustomOptions($customOptions, $payload, $maxChars);
        }
        return $payload;
    }

    private function fitCustomOptions(array $options, array $payload, int $maxChars): array
    {
        $budget = $maxChars - self::FENCE_MARGIN;
        if ($maxChars <= 0 || $this->encodedLength($payload + ['custom_options' => $options]) <= $budget) {
            return $options;
        }
        $low = 0;
        $high = max(array_map(static fn (array $option): int => count($option['values'] ?? []), $options));
        $best = $this->capOptionValues($options, 0);
        while ($low <= $high) {
            $limit = intdiv($low + $high, 2);
            $capped = $this->capOptionValues($options, $limit);
            if ($this->encodedLength($payload + ['custom_options' => $capped]) <= $budget) {
                $best = $capped;
                $low = $limit + 1;
                continue;
            }
            $high = $limit - 1;
        }
        return $best;
    }

    private function capOptionValues(array $options, int $limit): array
    {
        foreach ($options as $index => $option) {
            $count = count($option['values'] ?? []);
            if ($count <= $limit) {
                continue;
            }
            $options[$index]['values'] = array_slice($option['values'], 0, $limit);
            $options[$index]['more_values'] = $count - $limit;
            if ($limit === 0) {
                unset($options[$index]['values']);
            }
        }
        return $options;
    }

    private function encodedLength(array $payload): int
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return $encoded !== false ? mb_strlen($encoded) : PHP_INT_MAX;
    }

    public function searchResultText(string $query, array $products, int $maxChars): string
    {
        $payload = [
            'query' => $query,
            'result_count' => count($products),
            'results' => array_map(fn (array $product): array => $this->compactProduct($product), $products),
        ];
        $fenced = $this->fence->fencePayload($payload, $maxChars);
        return $this->searchResultHeader(count($products)) . "\n" . $fenced;
    }

    public function categorySearchText(string $keywords, array $matches, int $maxChars): string
    {
        $payload = [
            'keywords' => $keywords,
            'result_count' => count($matches),
            'results' => $matches,
        ];
        $fenced = $this->fence->fencePayload($payload, $maxChars);
        return $this->categorySearchHeader(count($matches)) . "\n" . $fenced;
    }

    private function categorySearchHeader(int $count): string
    {
        if ($count === 0) {
            return 'No category name matches these keywords. If a product search for the same thing '
                . 'also finds nothing that fits, the store does not carry it; say so and offer catalog '
                . 'map entries that suit the stated purpose.';
        }
        return "Category search returned {$count} match(es); pass a category_id to search_products to "
            . 'list or search inside one.';
    }

    public function cart(CartInterface $c): array
    {
        $items = [];
        foreach ($c->getItems() as $item) {
            $items[] = $this->cartLine($item);
        }
        $cart = [
            'items' => $items,
            'item_count' => $c->getItemCount(),
            'subtotal' => $c->getSubtotal(),
            'currency' => $c->getCurrency(),
        ];
        if (!$this->hasDiscount($c)) {
            return $cart;
        }
        return $cart + [
            'discount' => $c->getDiscount(),
            'subtotal_after_discount' => $c->getSubtotalAfterDiscount(),
            'coupon_code' => $c->getCouponCode(),
            'shipping_and_tax' => 'not included, added at checkout',
        ];
    }

    public function cartSummary(CartInterface $c): string
    {
        $summary = sprintf('%d item(s), subtotal %.2f %s', $c->getItemCount(), $c->getSubtotal(), $c->getCurrency());
        if (!$this->hasDiscount($c)) {
            return $summary;
        }
        $code = $c->getCouponCode() !== '' ? ' (code ' . $c->getCouponCode() . ')' : '';
        return $summary . sprintf(
            ', discount %.2f%s, after discount %.2f %s before shipping and tax',
            $c->getDiscount(),
            $code,
            $c->getSubtotalAfterDiscount(),
            $c->getCurrency()
        );
    }

    private function hasDiscount(CartInterface $c): bool
    {
        return $c->getDiscount() > 0.0 || $c->getCouponCode() !== '';
    }

    public function orders(array $orders, ?\DateTimeImmutable $now = null): array
    {
        if ($orders === []) {
            return ['note' => 'No orders found.'];
        }
        return array_map(fn (OrderInterface $order): array => $this->order($order, $now), $orders);
    }

    public function order(OrderInterface $o, ?\DateTimeImmutable $now = null): array
    {
        $items = [];
        foreach ($o->getItems() as $item) {
            $items[] = $this->orderItem($item);
        }
        $optional = $this->dropEmpty([
            'estimated_delivery' => $o->getEstimatedDelivery(),
            'tracking_url' => $o->getTrackingUrl(),
        ]);
        return [
            'order_id' => $o->getOrderId(),
            'status' => $o->getStatus(),
            'placed_at' => $o->getPlacedAt()->format(\DateTimeInterface::ATOM),
        ] + $this->orderAge($o, $now) + [
            'items' => $items,
            'total' => $o->getTotal(),
            'currency' => $o->getCurrency(),
        ] + $optional;
    }

    private function orderAge(OrderInterface $o, ?\DateTimeImmutable $now): array
    {
        if ($now === null) {
            return [];
        }
        $hours = intdiv(max(0, $now->getTimestamp() - $o->getPlacedAt()->getTimestamp()), 3600);
        return ['placed_hours_ago' => $hours, 'placed_days_ago' => intdiv($hours, 24)];
    }

    public function policies(array $policies): array
    {
        if ($policies === []) {
            return ['note' => 'No matching policy content.'];
        }
        return array_map(fn (PolicyInterface $p): array => $this->policy($p), $policies);
    }

    public function fulfillment(array $options): array
    {
        if ($options === []) {
            return ['note' => 'No fulfillment options available.'];
        }
        return array_map(fn (FulfillmentOptionInterface $o): array => $this->fulfillmentOption($o), $options);
    }

    private function policy(PolicyInterface $p): array
    {
        $data = [
            'policy_id' => $p->getPolicyId(),
            'title' => $p->getTitle(),
        ];
        if ($p->getCategory() !== null) {
            $data['category'] = $p->getCategory();
        }
        $data['content'] = $p->getContent();
        return $data;
    }

    private function fulfillmentOption(FulfillmentOptionInterface $o): array
    {
        $data = [
            'method' => $o->getMethod(),
            'eta' => $o->getEta(),
            'fee' => $o->getFee(),
        ];
        if ($o->getLocation() !== null) {
            $data['location'] = $o->getLocation();
        }
        return $data;
    }

    private function orderItem(OrderItemInterface $item): array
    {
        $optional = $this->dropEmpty([
            'option_values' => $item->getOptionValues(),
            'variant_of' => $item->getVariantOf(),
        ]);
        return [
            'product_id' => $item->getProductId(),
            'title' => $item->getTitle(),
            'quantity' => $item->getQuantity(),
            'price' => $item->getPrice(),
        ] + $optional;
    }

    private function cartLine(CartItemInterface $item): array
    {
        $optional = $this->dropEmpty([
            'image_url' => $item->getImageUrl(),
            'option_values' => $item->getOptionValues(),
            'variant_of' => $item->getVariantOf(),
        ]);
        return [
            'product_id' => $item->getProductId(),
            'title' => $item->getTitle(),
            'price' => $item->getPrice(),
            'quantity' => $item->getQuantity(),
        ] + $optional + ['line_total' => $item->getLineTotal()];
    }

    private function attributesDiff(array $variant, array $family): array
    {
        $variantAttributes = is_array($variant['attributes'] ?? null) ? $variant['attributes'] : [];
        $familyAttributes = is_array($family['attributes'] ?? null) ? $family['attributes'] : [];
        $diff = [];
        foreach ($variantAttributes as $key => $value) {
            if (!array_key_exists($key, $familyAttributes) || $familyAttributes[$key] != $value) {
                $diff[$key] = $value;
            }
        }
        return $diff;
    }

    private function searchResultHeader(int $count): string
    {
        if ($count === 0) {
            return self::SEARCH_EMPTY_HEADER;
        }
        return "Search returned {$count} result(s): the catalog's closest text matches, "
            . 'which can include related items rather than the exact thing searched for. '
            . 'Treat a result as the requested item only if its title and attributes match; '
            . 'if none do, the item was not found, and anything you offer instead is named '
            . 'as a stand-in.';
    }

    private function dropEmpty(array $data): array
    {
        return array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== []);
    }
}
