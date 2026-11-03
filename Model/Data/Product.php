<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\ProductInterface;

class Product implements ProductInterface
{
    public function __construct(
        private readonly string $productId,
        private readonly string $title,
        private readonly float $price,
        private readonly ?float $originalPrice = null,
        private readonly string $currency = 'USD',
        private readonly ?string $brand = null,
        private readonly ?float $rating = null,
        private readonly ?int $reviewCount = null,
        private readonly ?string $imageUrl = null,
        private readonly ?string $url = null,
        private readonly ?string $category = null,
        private readonly array $labels = [],
        private readonly array $attributes = [],
        private readonly ?bool $inStock = true,
        private readonly ?string $shortDescription = null,
        private readonly array $options = [],
        private readonly array $optionValues = [],
        private readonly ?string $variantOf = null,
        private readonly bool $hasRequiredCustomOptions = false,
        private readonly array $customOptions = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            productId: (string)($data['product_id'] ?? ''),
            title: (string)($data['title'] ?? ''),
            price: (float)($data['price'] ?? 0.0),
            originalPrice: isset($data['original_price']) ? (float)$data['original_price'] : null,
            currency: (string)($data['currency'] ?? 'USD'),
            brand: isset($data['brand']) ? (string)$data['brand'] : null,
            rating: isset($data['rating']) ? (float)$data['rating'] : null,
            reviewCount: isset($data['review_count']) ? (int)$data['review_count'] : null,
            imageUrl: isset($data['image_url']) ? (string)$data['image_url'] : null,
            url: isset($data['url']) ? (string)$data['url'] : null,
            category: isset($data['category']) ? (string)$data['category'] : null,
            labels: is_array($data['labels'] ?? null) ? array_values($data['labels']) : [],
            attributes: is_array($data['attributes'] ?? null) ? $data['attributes'] : [],
            inStock: array_key_exists('in_stock', $data) && $data['in_stock'] === null ? null : (bool)($data['in_stock'] ?? true),
            shortDescription: isset($data['short_description']) ? (string)$data['short_description'] : null,
            options: is_array($data['options'] ?? null) ? $data['options'] : [],
            optionValues: is_array($data['option_values'] ?? null) ? $data['option_values'] : [],
            variantOf: isset($data['variant_of']) ? (string)$data['variant_of'] : null,
            hasRequiredCustomOptions: (bool)($data['has_required_custom_options'] ?? false),
            customOptions: is_array($data['custom_options'] ?? null) ? $data['custom_options'] : []
        );
    }

    public function getProductId(): string
    {
        return $this->productId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBrand(): ?string
    {
        return $this->brand;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getOriginalPrice(): ?float
    {
        return $this->originalPrice;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getRating(): ?float
    {
        return $this->rating;
    }

    public function getReviewCount(): ?int
    {
        return $this->reviewCount;
    }

    public function getImageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function getLabels(): array
    {
        return $this->labels;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isInStock(): bool
    {
        return $this->inStock !== false;
    }

    public function isStockKnown(): bool
    {
        return $this->inStock !== null;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOptionValues(): array
    {
        return $this->optionValues;
    }

    public function getVariantOf(): ?string
    {
        return $this->variantOf;
    }

    public function hasRequiredCustomOptions(): bool
    {
        return $this->hasRequiredCustomOptions;
    }

    public function getCustomOptions(): array
    {
        return $this->customOptions;
    }

    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'title' => $this->title,
            'brand' => $this->brand,
            'price' => $this->price,
            'original_price' => $this->originalPrice,
            'currency' => $this->currency,
            'rating' => $this->rating,
            'review_count' => $this->reviewCount,
            'image_url' => $this->imageUrl,
            'url' => $this->url,
            'category' => $this->category,
            'labels' => $this->labels,
            'attributes' => $this->attributes,
            'in_stock' => $this->inStock,
            'short_description' => $this->shortDescription,
            'options' => $this->options,
            'option_values' => $this->optionValues,
            'variant_of' => $this->variantOf,
            'has_required_custom_options' => $this->hasRequiredCustomOptions,
            'custom_options' => $this->customOptions,
        ];
    }
}
