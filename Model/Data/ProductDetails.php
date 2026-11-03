<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Data;

use MageOS\AiShoppingAssistant\Api\Data\ProductDetailsInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductInterface;

class ProductDetails implements ProductDetailsInterface
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
        private readonly array $customOptions = [],
        private readonly ?string $longDescription = null,
        private readonly array $specs = [],
        private readonly array $variants = [],
        private readonly ?string $note = null
    ) {
    }

    public static function fromProduct(
        ProductInterface $product,
        ?string $longDescription,
        array $specs,
        array $variants,
        ?string $note = null
    ): self {
        $data = $product->toArray();
        $inStock = array_key_exists('in_stock', $data) ? $data['in_stock'] : $product->isInStock();

        return new self(
            productId: $product->getProductId(),
            title: $product->getTitle(),
            price: $product->getPrice(),
            originalPrice: $product->getOriginalPrice(),
            currency: $product->getCurrency(),
            brand: $product->getBrand(),
            rating: $product->getRating(),
            reviewCount: $product->getReviewCount(),
            imageUrl: $product->getImageUrl(),
            url: $product->getUrl(),
            category: $product->getCategory(),
            labels: $product->getLabels(),
            attributes: $product->getAttributes(),
            inStock: $inStock === null ? null : (bool)$inStock,
            shortDescription: $product->getShortDescription(),
            options: $product->getOptions(),
            optionValues: $product->getOptionValues(),
            variantOf: $product->getVariantOf(),
            hasRequiredCustomOptions: $product->hasRequiredCustomOptions(),
            customOptions: $product->getCustomOptions(),
            longDescription: $longDescription,
            specs: $specs,
            variants: $variants,
            note: $note
        );
    }

    public static function fromArray(array $data): self
    {
        $product = Product::fromArray($data);
        $variants = [];
        foreach ((is_array($data['variants'] ?? null) ? $data['variants'] : []) as $variant) {
            $variants[] = is_array($variant) ? Product::fromArray($variant) : $variant;
        }

        return self::fromProduct(
            $product,
            isset($data['long_description']) ? (string)$data['long_description'] : null,
            is_array($data['specs'] ?? null) ? $data['specs'] : [],
            $variants,
            isset($data['note']) ? (string)$data['note'] : null
        );
    }

    public function withVariants(array $variants): self
    {
        return new self(
            productId: $this->productId,
            title: $this->title,
            price: $this->price,
            originalPrice: $this->originalPrice,
            currency: $this->currency,
            brand: $this->brand,
            rating: $this->rating,
            reviewCount: $this->reviewCount,
            imageUrl: $this->imageUrl,
            url: $this->url,
            category: $this->category,
            labels: $this->labels,
            attributes: $this->attributes,
            inStock: $this->inStock,
            shortDescription: $this->shortDescription,
            options: $this->options,
            optionValues: $this->optionValues,
            variantOf: $this->variantOf,
            hasRequiredCustomOptions: $this->hasRequiredCustomOptions,
            customOptions: $this->customOptions,
            longDescription: $this->longDescription,
            specs: $this->specs,
            variants: $variants,
            note: $this->note
        );
    }

    public function withPrice(float $price): self
    {
        return new self(
            productId: $this->productId,
            title: $this->title,
            price: $price,
            originalPrice: $this->originalPrice,
            currency: $this->currency,
            brand: $this->brand,
            rating: $this->rating,
            reviewCount: $this->reviewCount,
            imageUrl: $this->imageUrl,
            url: $this->url,
            category: $this->category,
            labels: $this->labels,
            attributes: $this->attributes,
            inStock: $this->inStock,
            shortDescription: $this->shortDescription,
            options: $this->options,
            optionValues: $this->optionValues,
            variantOf: $this->variantOf,
            hasRequiredCustomOptions: $this->hasRequiredCustomOptions,
            customOptions: $this->customOptions,
            longDescription: $this->longDescription,
            specs: $this->specs,
            variants: $this->variants,
            note: $this->note
        );
    }

    public function withInStock(?bool $inStock): self
    {
        return new self(
            productId: $this->productId,
            title: $this->title,
            price: $this->price,
            originalPrice: $this->originalPrice,
            currency: $this->currency,
            brand: $this->brand,
            rating: $this->rating,
            reviewCount: $this->reviewCount,
            imageUrl: $this->imageUrl,
            url: $this->url,
            category: $this->category,
            labels: $this->labels,
            attributes: $this->attributes,
            inStock: $inStock,
            shortDescription: $this->shortDescription,
            options: $this->options,
            optionValues: $this->optionValues,
            variantOf: $this->variantOf,
            hasRequiredCustomOptions: $this->hasRequiredCustomOptions,
            customOptions: $this->customOptions,
            longDescription: $this->longDescription,
            specs: $this->specs,
            variants: $this->variants,
            note: $this->note
        );
    }

    public function withNote(?string $note): self
    {
        return new self(
            productId: $this->productId,
            title: $this->title,
            price: $this->price,
            originalPrice: $this->originalPrice,
            currency: $this->currency,
            brand: $this->brand,
            rating: $this->rating,
            reviewCount: $this->reviewCount,
            imageUrl: $this->imageUrl,
            url: $this->url,
            category: $this->category,
            labels: $this->labels,
            attributes: $this->attributes,
            inStock: $this->inStock,
            shortDescription: $this->shortDescription,
            options: $this->options,
            optionValues: $this->optionValues,
            variantOf: $this->variantOf,
            hasRequiredCustomOptions: $this->hasRequiredCustomOptions,
            customOptions: $this->customOptions,
            longDescription: $this->longDescription,
            specs: $this->specs,
            variants: $this->variants,
            note: $note
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

    public function getLongDescription(): ?string
    {
        return $this->longDescription;
    }

    public function getSpecs(): array
    {
        return $this->specs;
    }

    public function getVariants(): array
    {
        return $this->variants;
    }

    public function getNote(): ?string
    {
        return $this->note;
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
            'long_description' => $this->longDescription,
            'specs' => $this->specs,
            'variants' => array_map(
                static fn (ProductInterface $variant): array => $variant->toArray(),
                $this->variants
            ),
            'note' => $this->note,
        ];
    }
}
