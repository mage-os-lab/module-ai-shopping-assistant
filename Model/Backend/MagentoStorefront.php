<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Model\Backend;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Api\Data\ProductInterface as MagentoProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use MageOS\AiShoppingAssistant\Api\Data\CartInterface;
use MageOS\AiShoppingAssistant\Api\Data\OrderInterface as DataOrderInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductDetailsInterface;
use MageOS\AiShoppingAssistant\Api\Data\ProductInterface as DataProductInterface;
use MageOS\AiShoppingAssistant\Api\Data\SearchFiltersInterface;
use MageOS\AiShoppingAssistant\Api\Data\UserPreferencesInterface;
use MageOS\AiShoppingAssistant\Api\StorefrontBackendInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\NotOffered;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Data\Cart;
use MageOS\AiShoppingAssistant\Model\Data\CartItem;
use MageOS\AiShoppingAssistant\Model\Data\Product;
use MageOS\AiShoppingAssistant\Model\Data\ProductDetails;
use MageOS\AiShoppingAssistant\Model\Data\UserPreferences;

class MagentoStorefront implements StorefrontBackendInterface
{
    private const MAX_VARIANTS = 60;
    private const VARIANT_CAP_NOTE = 'More variants exist; ask about a size or colour to narrow the list.';

    public function __construct(
        private readonly \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        private readonly \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly \Magento\Framework\Api\SortOrderBuilder $sortOrderBuilder,
        private readonly \Magento\Store\Model\StoreManagerInterface $storeManager,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\ProductImageUrlInterface $productImageUrl,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\Provider\HelperImageUrl $helperImageUrl,
        private readonly \Magento\Catalog\Helper\Product\Configuration $productConfiguration,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\SearchProviderInterface $searchProvider,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\ProductMapper $productMapper,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\Salability $salability,
        private readonly \MageOS\AiShoppingAssistant\Api\Cart\BuyRequestBuilderInterface $buyRequestBuilder,
        private readonly \Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableResource,
        private readonly \Magento\Quote\Api\CartRepositoryInterface $cartRepository,
        private readonly \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        private readonly \Magento\Customer\Api\GroupRepositoryInterface $groupRepository,
        private readonly \Magento\Customer\Api\AddressRepositoryInterface $addressRepository,
        private readonly \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\OrderRecords $orderRecords,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\PolicySourceInterface $policySource,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\FulfillmentProviderInterface $fulfillmentProvider,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\Provider\AllowedCategories $allowedCategories,
        private readonly \MageOS\AiShoppingAssistant\Api\Backend\CategorySearchProviderInterface $categorySearchProvider,
        private readonly \MageOS\AiShoppingAssistant\Model\Backend\CartPriceDisplay $cartPriceDisplay
    ) {
    }

    public function searchProducts(
        SessionContext $ctx,
        string $query,
        ?SearchFiltersInterface $filters,
        int $limit
    ): array {
        $ids = $this->searchProvider->search($ctx, $query, $filters, $limit);
        if ($ids === []) {
            return [];
        }

        $products = $this->loadEnabledProducts($ids, $ctx->storeId, $limit);
        $byId = [];
        foreach ($products as $product) {
            $byId[(int)$product->getId()] = $product;
        }

        $this->productMapper->preloadRequestPaths($products, $ctx->storeId);
        $salableMap = $this->salability->areSalable($products, $ctx);

        $records = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $records[] = $this->productMapper->toProduct($byId[$id], $ctx, $salableMap[$id] ?? null);
            }
        }

        return $records;
    }

    private function loadEnabledProducts(array $ids, int $storeId, int $limit): array
    {
        $previousStoreId = (int)$this->storeManager->getStore()->getId();
        $this->storeManager->setCurrentStore($storeId);

        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $ids, 'in')
                ->addFilter('status', Status::STATUS_ENABLED)
                ->setPageSize($limit)
                ->create();
            return $this->productRepository->getList($criteria)->getItems();
        } finally {
            $this->storeManager->setCurrentStore($previousStoreId);
        }
    }

    public function getProductDetails(SessionContext $ctx, string $productId): ?ProductDetailsInterface
    {
        $product = $this->loadByIdOrSku($productId, $ctx->storeId);
        if ($product === null) {
            return null;
        }

        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            return null;
        }
        if ((int)$product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
            return null;
        }
        if (!$this->allowedCategories->permits((array)$product->getCategoryIds(), $ctx->storeId)) {
            return null;
        }
        if (!$this->assignedToWebsite($product, $ctx->storeId)) {
            return null;
        }

        $family = $this->productMapper->toProduct($product, $ctx);
        $details = ProductDetails::fromProduct(
            $family,
            $this->cleanText((string)$product->getData('description')),
            $this->specs($product),
            []
        );

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $details = $this->withVariants($details, $family, $product, $ctx);
        }

        return $details;
    }

    private function withVariants(
        ProductDetails $details,
        Product $family,
        MagentoProductInterface $product,
        SessionContext $ctx
    ): ProductDetails {
        $typeInstance = $product->getTypeInstance();
        if (!$typeInstance instanceof Configurable) {
            return $details;
        }

        $attributes = $typeInstance->getConfigurableAttributesAsArray($product);
        $usedProducts = array_slice($typeInstance->getUsedProducts($product), 0, self::MAX_VARIANTS + 1);
        $this->productMapper->preloadRequestPaths($usedProducts, $ctx->storeId);
        $salableMap = $this->salability->areSalable($usedProducts, $ctx);

        $variants = [];
        $anyInStock = false;
        $anyUnknown = false;
        $lowestPrice = null;

        foreach ($usedProducts as $child) {
            $childId = (int)$child->getId();
            $variantProduct = $this->productMapper->toProduct($child, $ctx, $salableMap[$childId] ?? null);
            $variantData = $variantProduct->toArray();
            $variantData['option_values'] = $this->variantOptionValues($attributes, $child);
            $variantData['variant_of'] = $family->getProductId();
            $variantData['url'] = $this->variantUrl($family->getUrl(), $attributes, $child);
            $variantData['options'] = [];
            $variant = Product::fromArray($variantData);
            $variants[] = $variant;

            $anyUnknown = $anyUnknown || !$variant->isStockKnown();
            if ($variant->isInStock()) {
                $anyInStock = $anyInStock || $variant->isStockKnown();
                if ($lowestPrice === null || $variant->getPrice() < $lowestPrice) {
                    $lowestPrice = $variant->getPrice();
                }
            }
        }

        $capped = count($variants) > self::MAX_VARIANTS;
        if ($capped) {
            usort(
                $variants,
                static fn (DataProductInterface $a, DataProductInterface $b): int => $a->getPrice() <=> $b->getPrice()
            );
            $variants = array_slice($variants, 0, self::MAX_VARIANTS);
        }

        $details = $details->withVariants($variants)->withInStock($anyInStock ?: ($anyUnknown ? null : false));
        if ($lowestPrice !== null) {
            $details = $details->withPrice($lowestPrice);
        }
        if ($capped) {
            $details = $details->withNote(self::VARIANT_CAP_NOTE);
        }

        return $details;
    }

    private function variantUrl(?string $familyUrl, array $attributes, MagentoProductInterface $child): ?string
    {
        if ($familyUrl === null || $familyUrl === '') {
            return $familyUrl;
        }

        $params = [];
        foreach ($attributes as $attribute) {
            $code = (string)($attribute['attribute_code'] ?? '');
            if ($code === '') {
                continue;
            }
            $value = $child->getData($code);
            if ($value === null || $value === '') {
                continue;
            }
            $params[$code] = (string)$value;
        }
        if ($params === []) {
            return $familyUrl;
        }

        $separator = str_contains($familyUrl, '?') ? '&' : '?';
        return $familyUrl . $separator . http_build_query($params);
    }

    private function variantOptionValues(array $attributes, MagentoProductInterface $child): array
    {
        $values = [];
        foreach ($attributes as $attribute) {
            $code = (string)($attribute['attribute_code'] ?? '');
            $label = (string)($attribute['label'] ?? '');
            if ($code === '' || $label === '') {
                continue;
            }
            $valueLabel = $this->optionLabel($attribute, $child->getData($code));
            if ($valueLabel !== null) {
                $values[$label] = $valueLabel;
            }
        }
        return $values;
    }

    private function optionLabel(array $attribute, mixed $value): ?string
    {
        foreach ((array)($attribute['options'] ?? []) as $option) {
            if ((string)($option['value'] ?? '') === (string)$value) {
                return isset($option['label']) ? (string)$option['label'] : null;
            }
        }
        return null;
    }

    private function assignedToWebsite(MagentoProductInterface $product, int $storeId): bool
    {
        $currentWebsiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $websiteIds = array_map('intval', (array)$product->getWebsiteIds());
        return in_array($currentWebsiteId, $websiteIds, true);
    }

    private function loadByIdOrSku(string $productId, int $storeId): ?MagentoProductInterface
    {
        try {
            if (ctype_digit($productId)) {
                return $this->productRepository->getById((int)$productId, false, $storeId);
            }
            return $this->productRepository->get($productId, false, $storeId);
        } catch (NoSuchEntityException $exception) {
            return null;
        }
    }

    private function specs(MagentoProductInterface $product): array
    {
        $excluded = ['description', 'short_description', 'manufacturer', 'sku', 'price', 'status', 'name'];
        $specs = [];

        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute instanceof AbstractAttribute
                || !$attribute->getIsVisibleOnFront()
                || in_array($attribute->getAttributeCode(), $excluded, true)
            ) {
                continue;
            }

            $value = $product->getAttributeText($attribute->getAttributeCode());
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            if ($value === false || $value === null || $value === '') {
                $value = $product->getData($attribute->getAttributeCode());
            }
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $specs[$attribute->getStoreLabel()] = (string)$value;
        }

        return $specs;
    }

    private function cleanText(string $text): ?string
    {
        $stripped = strip_tags($text);
        $collapsed = preg_replace('/\s+/', ' ', $stripped) ?? '';
        $trimmed = trim($this->collapseDoubledQuotes($collapsed));
        return $trimmed !== '' ? $trimmed : null;
    }

    private function collapseDoubledQuotes(string $text): string
    {
        return str_replace(['""', "''"], ['"', "'"], $text);
    }

    public function searchCategories(SessionContext $ctx, string $keywords, int $limit): array
    {
        return $this->categorySearchProvider->search($ctx, $keywords, $limit);
    }

    public function getCart(SessionContext $ctx): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);
        $items = [];
        $discount = 0.0;

        foreach ($quote->getAllVisibleItems() as $item) {
            $discount += abs((float)$item->getDiscountAmount());
            $isConfigurable = $item->getProductType() === Configurable::TYPE_CODE;
            $purchasable = $isConfigurable ? $this->childProduct($item) : $item->getProduct();
            if ($purchasable === null) {
                continue;
            }

            $items[] = new CartItem(
                (string)$purchasable->getId(),
                (string)$item->getName(),
                $this->itemPrice($item, $ctx->storeId),
                (int)$item->getQty(),
                $this->productImageUrl->forProduct($purchasable, 'product_thumbnail_image', $ctx->storeId)
                    ?? $this->helperImageUrl->forProduct($purchasable, 'product_thumbnail_image', $ctx->storeId),
                $this->itemOptionValues($item, $isConfigurable, $purchasable),
                $isConfigurable ? (string)$item->getProduct()->getId() : null,
                (int)$item->getId()
            );
        }

        $currency = $this->storeManager->getStore($ctx->storeId)->getCurrentCurrencyCode();
        return new Cart($items, $currency, round($discount, 2), (string)$quote->getCouponCode());
    }

    private function itemPrice(QuoteItem $item, int $storeId): float
    {
        $price = $this->cartPriceDisplay->includesTax($storeId) ? (float)$item->getPriceInclTax() : 0.0;
        return $price > 0.0 ? $price : (float)$item->getPrice();
    }

    private function itemOptionValues(QuoteItem $item, bool $isConfigurable, MagentoProductInterface $purchasable): array
    {
        $values = [];
        if ($isConfigurable) {
            $typeInstance = $item->getProduct()->getTypeInstance();
            if ($typeInstance instanceof Configurable) {
                $attributes = $typeInstance->getConfigurableAttributesAsArray($item->getProduct());
                $values = $this->variantOptionValues($attributes, $purchasable);
            }
        }

        foreach ($this->productConfiguration->getOptions($item) as $option) {
            $label = (string)($option['label'] ?? '');
            if ($label === '') {
                continue;
            }
            $value = $option['value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', $value);
            }
            $values[$label] = (string)$value;
        }

        return $values;
    }

    private function childProduct(QuoteItem $item): ?MagentoProductInterface
    {
        $option = $item->getOptionByCode('simple_product');
        return $option !== null ? $option->getProduct() : null;
    }

    public function addToCart(SessionContext $ctx, string $productId, int $quantity, array $options = []): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);

        try {
            $product = $this->productRepository->getById((int)$productId, false, $ctx->storeId);
        } catch (NoSuchEntityException $exception) {
            throw new Unavailable($productId . ' is out of stock');
        }

        if ((int)$product->getStatus() !== Status::STATUS_ENABLED) {
            throw new Unavailable($productId . ' is not available in this store');
        }

        if (!$this->assignedToWebsite($product, $ctx->storeId)) {
            throw new Unavailable($productId . ' is not available in this store');
        }

        if (!$this->salability->isSalable($product, $ctx)) {
            $siblings = $this->inStockSiblingIds($product, $ctx);
            $message = $productId . ' is out of stock';
            if ($siblings !== []) {
                $message .= '; in stock: ' . implode(', ', $siblings);
            }
            throw new Unavailable($message);
        }

        $resolvedOptions = $this->resolveCustomOptions($product, $options);

        $parentIds = $this->configurableResource->getParentIdsByChild($product->getId());
        $parentId = $parentIds[0] ?? null;

        if ($parentId !== null) {
            $parent = $this->productRepository->getById((int)$parentId, false, $ctx->storeId);
            $result = $this->addConfigurableToQuote($quote, $parent, $product, $quantity, $resolvedOptions);
        } else {
            $request = $this->buyRequestBuilder->build($product, $quantity, ['options' => $resolvedOptions]);
            $result = $this->addProductToQuote($quote, $product, $request);
        }

        if (is_string($result)) {
            throw new NotOffered($this->truncate($result));
        }

        $quote->getBillingAddress();
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->collectTotals();
        if ($quote->getHasError()) {
            $message = $this->quoteErrorText($quote, $result);
            $this->rollbackAdd($quote, $result);
            throw new NotOffered($this->truncate($message));
        }

        $this->cartRepository->save($quote);

        return $this->getCart($ctx);
    }

    private function rollbackAdd(Quote $quote, QuoteItem $item): void
    {
        if (!$item->getId()) {
            $quote->deleteItem($item);
            return;
        }
        $item->setQty((float)$item->getPreviousQty());
    }

    private function quoteErrorText(Quote $quote, QuoteItem $item): string
    {
        $texts = [];
        foreach ((array)$item->getMessage(false) as $message) {
            $texts[] = trim((string)$message);
        }
        if ($texts === []) {
            foreach ($quote->getErrors() as $error) {
                $texts[] = trim((string)$error->getText());
            }
        }

        $texts = array_values(array_unique(array_filter($texts)));
        return $texts !== [] ? implode(' ', $texts) : 'The cart could not be updated.';
    }

    private function resolveCustomOptions(MagentoProductInterface $product, array $options): array
    {
        if ($options === []) {
            return [];
        }

        $productName = (string)$product->getName();
        $available = (array)$product->getOptions();
        $resolved = [];

        foreach ($options as $title => $selection) {
            $title = (string)$title;
            $option = $this->findCustomOptionByTitle($available, $title);
            if ($option === null) {
                throw new NotOffered($productName . ' has no option ' . $title);
            }
            $resolved[(int)$option->getOptionId()] = $this->resolveCustomOptionValue(
                $option,
                (string)$selection,
                $title,
                $productName
            );
        }

        return $resolved;
    }

    private function resolveCustomOptionValue(
        ProductCustomOptionInterface $option,
        string $selection,
        string $title,
        string $productName
    ): string {
        $type = (string)$option->getType();

        if (in_array($type, [
            ProductCustomOptionInterface::OPTION_TYPE_FIELD,
            ProductCustomOptionInterface::OPTION_TYPE_AREA,
        ], true)) {
            return $selection;
        }

        if (in_array($type, [
            ProductCustomOptionInterface::OPTION_TYPE_CHECKBOX,
            ProductCustomOptionInterface::OPTION_TYPE_MULTIPLE,
        ], true)) {
            $ids = [];
            foreach (array_map('trim', explode(',', $selection)) as $part) {
                $ids[] = $this->resolveCustomOptionValueId($option, $part, $title, $productName);
            }
            return implode(',', $ids);
        }

        return $this->resolveCustomOptionValueId($option, $selection, $title, $productName);
    }

    private function resolveCustomOptionValueId(
        ProductCustomOptionInterface $option,
        string $valueTitle,
        string $title,
        string $productName
    ): string {
        $value = $this->findCustomOptionValueByTitle($option, $valueTitle);
        if ($value === null) {
            throw new NotOffered($productName . ' has no value ' . $valueTitle . ' for ' . $title);
        }
        return (string)$value->getOptionTypeId();
    }

    private function findCustomOptionByTitle(array $options, string $title): ?ProductCustomOptionInterface
    {
        foreach ($options as $option) {
            if ($option instanceof ProductCustomOptionInterface && strcasecmp((string)$option->getTitle(), $title) === 0) {
                return $option;
            }
        }
        return null;
    }

    private function findCustomOptionValueByTitle(
        ProductCustomOptionInterface $option,
        string $title
    ): ?ProductCustomOptionValuesInterface {
        foreach ((array)$option->getValues() as $value) {
            if ($value instanceof ProductCustomOptionValuesInterface && strcasecmp((string)$value->getTitle(), $title) === 0) {
                return $value;
            }
        }
        return null;
    }

    private function addConfigurableToQuote(
        Quote $quote,
        MagentoProductInterface $parent,
        MagentoProductInterface $child,
        int $quantity,
        array $resolvedOptions
    ) {
        $selections = [];
        $typeInstance = $parent->getTypeInstance();
        if ($typeInstance instanceof Configurable) {
            foreach ($typeInstance->getConfigurableAttributesAsArray($parent) as $attribute) {
                $attributeId = (string)($attribute['attribute_id'] ?? '');
                $code = (string)($attribute['attribute_code'] ?? '');
                if ($attributeId === '' || $code === '') {
                    continue;
                }
                $selections[$attributeId] = $child->getData($code);
            }
        }

        $request = $this->buyRequestBuilder->build(
            $parent,
            $quantity,
            ['super_attribute' => $selections, 'options' => $resolvedOptions]
        );

        return $this->addProductToQuote($quote, $parent, $request);
    }

    private function addProductToQuote(Quote $quote, MagentoProductInterface $product, DataObject $request)
    {
        try {
            return $quote->addProduct($product, $request);
        } catch (LocalizedException $exception) {
            throw new NotOffered($this->truncate($exception->getMessage()));
        }
    }

    private function inStockSiblingIds(MagentoProductInterface $product, SessionContext $ctx): array
    {
        $parentIds = $this->configurableResource->getParentIdsByChild($product->getId());
        $parentId = $parentIds[0] ?? null;
        if ($parentId === null) {
            return [];
        }

        try {
            $parent = $this->productRepository->getById((int)$parentId, false, $ctx->storeId);
        } catch (NoSuchEntityException $exception) {
            return [];
        }

        $typeInstance = $parent->getTypeInstance();
        if (!$typeInstance instanceof Configurable) {
            return [];
        }

        $siblings = [];
        foreach ($typeInstance->getUsedProducts($parent) as $child) {
            if ((int)$child->getId() === (int)$product->getId()) {
                continue;
            }
            if ($this->salability->isSalable($child, $ctx)) {
                $siblings[] = (string)$child->getId();
            }
        }
        return $siblings;
    }

    private function truncate(string $message): string
    {
        return mb_substr(trim($message), 0, 200);
    }

    public function updateCartItem(SessionContext $ctx, string $productId, int $quantity): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);
        $item = $this->findVisibleItem($quote, $productId);
        if ($item === null) {
            return $this->getCart($ctx);
        }

        $previousQty = (float)$item->getQty();
        $item->setQty($quantity);
        $quote->getBillingAddress();
        $quote->getShippingAddress()->setCollectShippingRates(true);
        $quote->collectTotals();
        if ($quote->getHasError()) {
            $message = $this->quoteErrorText($quote, $item);
            $item->setQty($previousQty);
            throw new NotOffered($this->truncate($message));
        }

        $this->cartRepository->save($quote);

        return $this->getCart($ctx);
    }

    public function removeFromCart(SessionContext $ctx, string $productId): CartInterface
    {
        $quote = $this->cartRepository->get($ctx->quoteId);
        $item = $this->findVisibleItem($quote, $productId);
        if ($item !== null) {
            $quote->removeItem((int)$item->getId());
            $quote->getBillingAddress();
            $quote->getShippingAddress()->setCollectShippingRates(true);
            $quote->collectTotals();
            $this->cartRepository->save($quote);
        }

        return $this->getCart($ctx);
    }

    private function findVisibleItem(Quote $quote, string $productId): ?QuoteItem
    {
        foreach ($quote->getAllVisibleItems() as $item) {
            $isConfigurable = $item->getProductType() === Configurable::TYPE_CODE;
            $purchasable = $isConfigurable ? $this->childProduct($item) : $item->getProduct();
            if ($purchasable !== null && (string)$purchasable->getId() === $productId) {
                return $item;
            }
        }
        return null;
    }

    public function getPreferences(SessionContext $ctx): UserPreferencesInterface
    {
        if ($ctx->customerId === null) {
            return new UserPreferences('guest:' . $ctx->quoteId);
        }

        try {
            $customer = $this->customerRepository->getById($ctx->customerId);
        } catch (NoSuchEntityException $exception) {
            return new UserPreferences('guest:' . $ctx->quoteId);
        }

        $loyaltyTier = null;
        try {
            $loyaltyTier = $this->groupRepository->getById((int)$customer->getGroupId())->getCode();
        } catch (NoSuchEntityException $exception) {
            $loyaltyTier = null;
        }

        return new UserPreferences(
            (string)$customer->getId(),
            $customer->getFirstname(),
            $loyaltyTier,
            $this->customerLocation($customer->getDefaultShipping())
        );
    }

    private function customerLocation(?string $defaultShippingId): ?string
    {
        if ($defaultShippingId === null) {
            return null;
        }

        try {
            $address = $this->addressRepository->getById((int)$defaultShippingId);
        } catch (NoSuchEntityException $exception) {
            return null;
        }

        $city = $address->getCity();
        $region = $address->getRegion();
        $regionText = $region !== null ? $region->getRegion() : null;

        if ($city !== null && $regionText !== null) {
            return $city . ', ' . $regionText;
        }
        return $city;
    }

    public function getOrders(SessionContext $ctx, int $limit): array
    {
        if ($ctx->customerId === null) {
            throw new SignInRequired();
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('customer_id', $ctx->customerId)
            ->addFilter('store_id', $ctx->storeId)
            ->addSortOrder($this->sortOrderBuilder->setField('created_at')->setDirection(SortOrder::SORT_DESC)->create())
            ->setPageSize($limit)
            ->create();

        return $this->orderRecords->fromSalesOrders($this->orderRepository->getList($criteria)->getItems(), $ctx);
    }

    public function getOrder(SessionContext $ctx, string $orderId): ?DataOrderInterface
    {
        if ($ctx->customerId === null) {
            throw new SignInRequired();
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $orderId)
            ->addFilter('customer_id', $ctx->customerId)
            ->setPageSize(1)
            ->create();

        $items = $this->orderRepository->getList($criteria)->getItems();
        $first = reset($items);
        if ($first === false) {
            return null;
        }

        return $this->orderRecords->fromSalesOrders([$first], $ctx)[0];
    }

    public function searchPolicies(SessionContext $ctx, string $query): array
    {
        return $this->policySource->search($ctx, $query);
    }

    public function getFulfillmentOptions(SessionContext $ctx, array $productIds): array
    {
        return $this->fulfillmentProvider->options($ctx, array_slice($productIds, 0, 20));
    }
}
