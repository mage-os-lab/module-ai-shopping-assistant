<?php
declare(strict_types=1);

namespace MageOS\AiShoppingAssistant\Test\Unit\Backend;

use Magento\Catalog\Api\Data\ProductCustomOptionInterface;
use Magento\Catalog\Api\Data\ProductCustomOptionValuesInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Product\Configuration as ProductConfigurationHelper;
use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product as MagentoProduct;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable as ConfigurableResource;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\Data\IsProductSalableResultInterface;
use Magento\InventorySalesApi\Api\IsProductSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Item\Option as QuoteItemOption;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\Data\ShipmentSearchResultInterface;
use Magento\Sales\Api\Data\ShipmentTrackSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Item as SalesOrderItem;
use Magento\Shipping\Helper\Data as ShippingHelper;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use MageOS\AiShoppingAssistant\Api\Backend\CategorySearchProviderInterface;
use MageOS\AiShoppingAssistant\Api\Backend\FulfillmentProviderInterface;
use MageOS\AiShoppingAssistant\Api\Backend\OrderStatusMapperInterface;
use MageOS\AiShoppingAssistant\Api\Backend\PolicySourceInterface;
use MageOS\AiShoppingAssistant\Api\Backend\ProductImageUrlInterface;
use MageOS\AiShoppingAssistant\Api\Backend\SearchProviderInterface;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\NotOffered;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\SignInRequired;
use MageOS\AiShoppingAssistant\Model\Agent\Exception\Unavailable;
use MageOS\AiShoppingAssistant\Model\Agent\SessionContext;
use MageOS\AiShoppingAssistant\Model\Backend\BuyRequestBuilder;
use MageOS\AiShoppingAssistant\Model\Backend\MagentoStorefront;
use MageOS\AiShoppingAssistant\Model\Backend\OrderRecords;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\AllowedCategories;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\CoreOptions;
use MageOS\AiShoppingAssistant\Model\Backend\Provider\HelperImageUrl;
use MageOS\AiShoppingAssistant\Model\Backend\ProductMapper;
use MageOS\AiShoppingAssistant\Model\Backend\CartPriceDisplay;
use MageOS\AiShoppingAssistant\Model\Backend\Salability;
use MageOS\AiShoppingAssistant\Model\Config\StoreConfig;
use MageOS\AiShoppingAssistant\Model\Data\PageContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MagentoStorefrontTest extends TestCase
{
    private function context(?int $customerId = null): SessionContext
    {
        return new SessionContext('session-1', $customerId, 1, 1, new PageContext(), new \DateTimeImmutable('now'));
    }

    private function store(): Store&MockObject
    {
        $website = $this->createMock(Website::class);
        $website->method('getCode')->willReturn('base');

        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getCurrentCurrencyCode')->willReturn('USD');
        $store->method('getWebsite')->willReturn($website);
        $store->method('getWebsiteId')->willReturn(1);
        return $store;
    }

    private function storeManager(): StoreManagerInterface&MockObject
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->store());
        return $storeManager;
    }

    private function productImageUrl(): ProductImageUrlInterface&MockObject
    {
        $productImageUrl = $this->createMock(ProductImageUrlInterface::class);
        $productImageUrl->method('forProduct')->willReturn('https://example.test/img.jpg');
        return $productImageUrl;
    }

    private function helperImageUrl(): HelperImageUrl
    {
        $imageHelper = $this->createMock(\Magento\Catalog\Helper\Image::class);
        $imageHelper->method('init')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://example.test/placeholder.jpg');
        return new HelperImageUrl($imageHelper);
    }

    private function priceInfo(float $value): PriceInfoInterface&MockObject
    {
        $price = $this->createMock(PriceInterface::class);
        $price->method('getValue')->willReturn($value);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->willReturn($price);
        return $priceInfo;
    }

    private function magentoProduct(int $id, string $name, float $price): MagentoProduct&MockObject
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo($price));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getOptions')->willReturn([]);
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getWebsiteIds')->willReturn([1]);
        return $product;
    }

    private function cartPriceDisplay(bool $includesTax): CartPriceDisplay
    {
        $display = $this->createMock(CartPriceDisplay::class);
        $display->method('includesTax')->willReturn($includesTax);
        return $display;
    }

    private function salability(bool $result): Salability
    {
        return $this->salabilityBySku([], $result);
    }

    private function salabilityBySku(array $bySku, bool $default = true): Salability
    {
        $isProductSalable = $this->createMock(IsProductSalableInterface::class);
        $isProductSalable->method('execute')->willReturnCallback(
            static fn (string $sku, int $stockId): bool => $bySku[$sku] ?? $default
        );

        $areProductsSalable = $this->createMock(AreProductsSalableInterface::class);
        $areProductsSalable->method('execute')->willReturnCallback(
            function (array $skus) use ($bySku, $default): array {
                $results = [];
                foreach ($skus as $sku) {
                    $result = $this->createMock(IsProductSalableResultInterface::class);
                    $result->method('getSku')->willReturn($sku);
                    $result->method('isSalable')->willReturn($bySku[$sku] ?? $default);
                    $results[] = $result;
                }
                return $results;
            }
        );

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturn($stock);

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [StockResolverInterface::class, $stockResolver],
            [IsProductSalableInterface::class, $isProductSalable],
            [AreProductsSalableInterface::class, $areProductsSalable],
        ]);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);

        return new Salability($objectManager, $stockRegistry, $this->storeManager());
    }

    private function productMapper(): ProductMapper
    {
        return new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $this->urlFinder()
        );
    }

    private function urlFinder(array $rewrites = []): UrlFinderInterface&MockObject
    {
        $urlFinder = $this->createMock(UrlFinderInterface::class);
        $urlFinder->method('findAllByData')->willReturn($rewrites);
        return $urlFinder;
    }

    private function shipmentTrackRepository(array $tracks = []): ShipmentTrackRepositoryInterface&MockObject
    {
        $results = $this->createMock(ShipmentTrackSearchResultInterface::class);
        $results->method('getItems')->willReturn($tracks);
        $repository = $this->createMock(ShipmentTrackRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);
        return $repository;
    }

    private function shipmentRepository(array $shipments = []): ShipmentRepositoryInterface&MockObject
    {
        $results = $this->createMock(ShipmentSearchResultInterface::class);
        $results->method('getItems')->willReturn($shipments);
        $repository = $this->createMock(ShipmentRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);
        return $repository;
    }

    private function searchCriteriaBuilder(): SearchCriteriaBuilder&MockObject
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('addSortOrder')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        return $builder;
    }

    private function sortOrderBuilder(): SortOrderBuilder&MockObject
    {
        $builder = $this->createMock(SortOrderBuilder::class);
        $builder->method('setField')->willReturnSelf();
        $builder->method('setDirection')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SortOrder::class));
        return $builder;
    }

    private function storeConfig(array $allowedCategories): StoreConfig
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $path === 'ai_integration/aiagent/content/allowed_categories'
                ? implode(',', $allowedCategories)
                : null
        );
        $scopeConfig->method('isSetFlag')->willReturn(false);

        return new StoreConfig($scopeConfig, $this->storeManager());
    }

    private function category(int $id, string $path): Category&MockObject
    {
        $category = $this->createMock(Category::class);
        $category->method('getId')->willReturn($id);
        $category->method('getPath')->willReturn($path);
        return $category;
    }

    private function allowedCategories(
        array $allowedCategories = [],
        array $categories = [],
        ?CategoryCollectionFactory $collectionFactory = null
    ): AllowedCategories {
        if ($collectionFactory === null) {
            $collection = $this->createMock(CategoryCollection::class);
            $collection->method('setStoreId')->willReturnSelf();
            $collection->method('addAttributeToSelect')->willReturnSelf();
            $collection->method('addIdFilter')->willReturnSelf();
            $collection->method('getIterator')->willReturn(new \ArrayIterator($categories));

            $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
            $collectionFactory->method('create')->willReturn($collection);
        }

        return (new ObjectManager($this))->getObject(AllowedCategories::class, [
            'collectionFactory' => $collectionFactory,
            'storeConfig' => $this->storeConfig($allowedCategories),
        ]);
    }

    private function detailedProduct(int $id, array $categoryIds): MagentoProduct&MockObject
    {
        $product = $this->magentoProduct($id, 'Product ' . $id, 10.0);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $product->method('getAttributes')->willReturn([]);
        $product->method('getCategoryIds')->willReturn($categoryIds);
        return $product;
    }

    private function defaultDependencies(): array
    {
        return [
            'productRepository' => $this->createMock(ProductRepositoryInterface::class),
            'searchCriteriaBuilder' => $this->searchCriteriaBuilder(),
            'sortOrderBuilder' => $this->sortOrderBuilder(),
            'storeManager' => $this->storeManager(),
            'productImageUrl' => $this->productImageUrl(),
            'helperImageUrl' => $this->helperImageUrl(),
            'productConfiguration' => $this->createMock(ProductConfigurationHelper::class),
            'searchProvider' => $this->createMock(SearchProviderInterface::class),
            'productMapper' => $this->productMapper(),
            'salability' => $this->salability(true),
            'buyRequestBuilder' => new BuyRequestBuilder(),
            'configurableResource' => $this->createMock(ConfigurableResource::class),
            'cartRepository' => $this->createMock(CartRepositoryInterface::class),
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'groupRepository' => $this->createMock(GroupRepositoryInterface::class),
            'addressRepository' => $this->createMock(AddressRepositoryInterface::class),
            'orderRepository' => $this->createMock(OrderRepositoryInterface::class),
            'orderStatusMapper' => $this->createMock(OrderStatusMapperInterface::class),
            'shippingHelper' => $this->createMock(ShippingHelper::class),
            'policySource' => $this->createMock(PolicySourceInterface::class),
            'fulfillmentProvider' => $this->createMock(FulfillmentProviderInterface::class),
            'categorySearchProvider' => $this->createMock(CategorySearchProviderInterface::class),
            'cartPriceDisplay' => $this->cartPriceDisplay(true),
            'allowedCategories' => $this->allowedCategories(),
            'shipmentTrackRepository' => $this->shipmentTrackRepository(),
            'shipmentRepository' => $this->shipmentRepository(),
        ];
    }

    private function buildStorefront(array $overrides = []): MagentoStorefront
    {
        $deps = array_merge($this->defaultDependencies(), $overrides);
        $deps['orderRecords'] = new OrderRecords(
            $deps['productRepository'],
            $deps['searchCriteriaBuilder'],
            $deps['storeManager'],
            $deps['orderStatusMapper'],
            $deps['shippingHelper'],
            $deps['shipmentTrackRepository'],
            $deps['shipmentRepository']
        );
        unset($deps['orderStatusMapper'], $deps['shippingHelper'], $deps['shipmentTrackRepository'], $deps['shipmentRepository']);
        return new MagentoStorefront(...$deps);
    }

    public function testSearchProductsOrdersByRankAndDropsMissingIds(): void
    {
        $searchProvider = $this->createMock(SearchProviderInterface::class);
        $searchProvider->method('search')->willReturn([10, 20, 30]);

        $product10 = $this->magentoProduct(10, 'Product Ten', 10.0);
        $product30 = $this->magentoProduct(30, 'Product Thirty', 30.0);

        $results = $this->createMock(ProductSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$product30, $product10]);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getList')->willReturn($results);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'searchProvider' => $searchProvider,
        ]);

        $records = $storefront->searchProducts($this->context(), 'widget', null, 10);

        $this->assertCount(2, $records);
        $this->assertSame('10', $records[0]->getProductId());
        $this->assertSame('30', $records[1]->getProductId());
    }

    public function testSearchProductsReturnsEmptyWhenSearchProviderFindsNothing(): void
    {
        $searchProvider = $this->createMock(SearchProviderInterface::class);
        $searchProvider->method('search')->willReturn([]);

        $storefront = $this->buildStorefront(['searchProvider' => $searchProvider]);

        $this->assertSame([], $storefront->searchProducts($this->context(), 'widget', null, 10));
    }

    public function testGetCartMapsConfigurableItemToChildWithVariantOf(): void
    {
        $child = $this->magentoProduct(501, 'Red Shirt - M', 25.0);
        $child->method('getData')->willReturnCallback(
            static fn (string $key = '') => $key === 'color' ? '10' : null
        );

        $parentTypeInstance = $this->createMock(Configurable::class);
        $parentTypeInstance->method('getConfigurableAttributesAsArray')->willReturn([
            [
                'attribute_code' => 'color',
                'label' => 'Color',
                'options' => [['value' => '10', 'label' => 'Red']],
            ],
        ]);
        $parent = $this->magentoProduct(500, 'Shirt', 25.0);
        $parent->method('getTypeInstance')->willReturn($parentTypeInstance);

        $option = $this->createMock(QuoteItemOption::class);
        $option->method('getProduct')->willReturn($child);

        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductType', 'getOptionByCode', 'getProduct', 'getName', 'getQty', 'getId', 'getPrice', '__call'])
            ->getMock();
        $item->method('getProductType')->willReturn(Configurable::TYPE_CODE);
        $item->method('getOptionByCode')->with('simple_product')->willReturn($option);
        $item->method('getProduct')->willReturn($parent);
        $item->method('getName')->willReturn('Red Shirt - M');
        $item->method('getQty')->willReturn(2.0);
        $item->method('getId')->willReturn(77);
        $item->method('__call')->willReturnCallback(static fn (string $name) => $name === 'getPriceInclTax' ? 25.0 : null);
        $item->method('getPrice')->willReturn(25.0);

        $quote = $this->createMock(Quote::class);
        $quote->method('getAllVisibleItems')->willReturn([$item]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $productConfiguration = $this->createMock(ProductConfigurationHelper::class);
        $productConfiguration->method('getOptions')->willReturn([]);

        $storefront = $this->buildStorefront([
            'cartRepository' => $cartRepository,
            'productConfiguration' => $productConfiguration,
        ]);

        $cart = $storefront->getCart($this->context());

        $this->assertCount(1, $cart->getItems());
        $cartItem = $cart->getItems()[0];
        $this->assertSame('501', $cartItem->getProductId());
        $this->assertSame('500', $cartItem->getVariantOf());
        $this->assertSame(['Color' => 'Red'], $cartItem->getOptionValues());
        $this->assertSame(2, $cartItem->getQuantity());
        $this->assertSame(77, $cartItem->getItemId());
    }

    public function testGetCartFallsBackToTheHelperImageWhenTheProviderHasNoUrl(): void
    {
        $product = $this->magentoProduct(600, 'Lamp', 40.0);

        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getProductType', 'getProduct', 'getName', 'getQty', 'getId', 'getPrice', '__call'])
            ->getMock();
        $item->method('getProductType')->willReturn('simple');
        $item->method('getProduct')->willReturn($product);
        $item->method('getName')->willReturn('Lamp');
        $item->method('getQty')->willReturn(1.0);
        $item->method('getId')->willReturn(88);
        $item->method('__call')->willReturnCallback(static fn (string $name) => $name === 'getPriceInclTax' ? 40.0 : null);
        $item->method('getPrice')->willReturn(40.0);

        $quote = $this->createMock(Quote::class);
        $quote->method('getAllVisibleItems')->willReturn([$item]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $productConfiguration = $this->createMock(ProductConfigurationHelper::class);
        $productConfiguration->method('getOptions')->willReturn([]);

        $productImageUrl = $this->createMock(ProductImageUrlInterface::class);
        $productImageUrl->method('forProduct')->willReturn(null);

        $storefront = $this->buildStorefront([
            'cartRepository' => $cartRepository,
            'productConfiguration' => $productConfiguration,
            'productImageUrl' => $productImageUrl,
            'helperImageUrl' => $this->helperImageUrl(),
        ]);

        $cart = $storefront->getCart($this->context());

        $this->assertSame('https://example.test/placeholder.jpg', $cart->getItems()[0]->getImageUrl());
    }

    public function testGetCartQuotesLinePricesWithOrWithoutTaxAsConfigured(): void
    {
        foreach ([[true, 176.4], [false, 157.5]] as [$includesTax, $expected]) {
            $item = $this->getMockBuilder(QuoteItem::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getProductType', 'getProduct', 'getName', 'getQty', 'getId', 'getPrice', '__call'])
                ->getMock();
            $item->method('getProductType')->willReturn('simple');
            $item->method('getProduct')->willReturn($this->magentoProduct(700, 'Milk Boiler', 157.5));
            $item->method('getName')->willReturn('Milk Boiler');
            $item->method('getQty')->willReturn(1.0);
            $item->method('getId')->willReturn(91);
            $item->method('__call')->willReturnCallback(static fn (string $name) => $name === 'getPriceInclTax' ? 176.4 : null);
            $item->method('getPrice')->willReturn(157.5);
            $quote = $this->createMock(Quote::class);
            $quote->method('getAllVisibleItems')->willReturn([$item]);
            $cartRepository = $this->createMock(CartRepositoryInterface::class);
            $cartRepository->method('get')->willReturn($quote);
            $productConfiguration = $this->createMock(ProductConfigurationHelper::class);
            $productConfiguration->method('getOptions')->willReturn([]);

            $cart = $this->buildStorefront([
                'cartRepository' => $cartRepository,
                'productConfiguration' => $productConfiguration,
                'cartPriceDisplay' => $this->cartPriceDisplay($includesTax),
            ])->getCart($this->context());

            $this->assertSame($expected, $cart->getItems()[0]->getPrice());
        }
    }

    public function testAddToCartRaisesUnavailableWithSiblingIds(): void
    {
        $child = $this->magentoProduct(201, 'Blue Shirt - S', 20.0);
        $child->method('getSku')->willReturn('SHIRT-BLUE-S');
        $child->method('getData')->willReturnCallback(static fn (string $key) => $key === 'sku' ? 'SHIRT-BLUE-S' : null);

        $sibling1 = $this->magentoProduct(202, 'Blue Shirt - M', 20.0);
        $sibling1->method('getSku')->willReturn('SHIRT-BLUE-M');
        $sibling1->method('getData')->willReturnCallback(static fn (string $key) => $key === 'sku' ? 'SHIRT-BLUE-M' : null);
        $sibling2 = $this->magentoProduct(203, 'Blue Shirt - L', 20.0);
        $sibling2->method('getSku')->willReturn('SHIRT-BLUE-L');
        $sibling2->method('getData')->willReturnCallback(static fn (string $key) => $key === 'sku' ? 'SHIRT-BLUE-L' : null);

        $parentTypeInstance = $this->createMock(Configurable::class);
        $parentTypeInstance->method('getUsedProducts')->willReturn([$child, $sibling1, $sibling2]);
        $parent = $this->magentoProduct(200, 'Blue Shirt', 20.0);
        $parent->method('getTypeInstance')->willReturn($parentTypeInstance);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturnCallback(
            static fn (int $id) => match ($id) {
                201 => $child,
                200 => $parent,
                default => null,
            }
        );

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn(['200']);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku([
                'SHIRT-BLUE-S' => false,
                'SHIRT-BLUE-M' => true,
                'SHIRT-BLUE-L' => true,
            ]),
        ]);

        $this->expectException(Unavailable::class);
        $this->expectExceptionMessage('201 is out of stock; in stock: 202, 203');

        $storefront->addToCart($this->context(), '201', 1);
    }

    public function testAddToCartRejectsADisabledProduct(): void
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn(210);
        $product->method('getStatus')->willReturn(Status::STATUS_DISABLED);
        $product->method('getWebsiteIds')->willReturn(['1']);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'cartRepository' => $cartRepository,
        ]);

        $this->expectException(Unavailable::class);
        $this->expectExceptionMessage('210 is not available in this store');

        $storefront->addToCart($this->context(), '210', 1);
    }

    public function testAddToCartRejectsAProductNotAssignedToTheCurrentWebsite(): void
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn(211);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getWebsiteIds')->willReturn([2]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'cartRepository' => $cartRepository,
        ]);

        $this->expectException(Unavailable::class);
        $this->expectExceptionMessage('211 is not available in this store');

        $storefront->addToCart($this->context(), '211', 1);
    }

    public function testAddToCartUsesTheLoadedMagentoProductForTheBuyRequest(): void
    {
        $product = $this->magentoProduct(301, 'Solo Item', 12.0);
        $product->method('getSku')->willReturn('SOLO-1');

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();
        $shippingAddress->expects($this->once())->method('__call')->with('setCollectShippingRates', [true]);

        $quote = $this->createMock(Quote::class);
        $quote->expects($this->once())->method('addProduct')->with(
            $product,
            $this->callback(static fn (DataObject $request): bool => $request->getData('product') === 301
                && $request->getData('qty') === 1)
        )->willReturn(true);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->expects($this->once())->method('getBillingAddress')->willReturn($this->createMock(QuoteAddress::class));
        $quote->expects($this->once())->method('getShippingAddress')->willReturn($shippingAddress);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['SOLO-1' => true]),
        ]);

        $storefront->addToCart($this->context(), '301', 1);
    }

    private function sizeCustomOption(): ProductCustomOptionInterface&MockObject
    {
        $small = $this->createMock(ProductCustomOptionValuesInterface::class);
        $small->method('getTitle')->willReturn('1 CUP');
        $small->method('getOptionTypeId')->willReturn(11);

        $large = $this->createMock(ProductCustomOptionValuesInterface::class);
        $large->method('getTitle')->willReturn('3 CUP');
        $large->method('getOptionTypeId')->willReturn(12);

        $option = $this->createMock(ProductCustomOptionInterface::class);
        $option->method('getOptionId')->willReturn(7);
        $option->method('getTitle')->willReturn('Size');
        $option->method('getType')->willReturn('drop_down');
        $option->method('getValues')->willReturn([$small, $large]);

        return $option;
    }

    private function productWithCustomOption(
        int $id,
        string $name,
        float $price,
        string $sku,
        ProductCustomOptionInterface $option
    ): MagentoProduct&MockObject {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getPriceInfo')->willReturn($this->priceInfo($price));
        $product->method('getProductUrl')->willReturn(null);
        $product->method('getSku')->willReturn($sku);
        $product->method('getAttributeText')->willReturn(false);
        $product->method('getOptions')->willReturn([$option]);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getWebsiteIds')->willReturn([1]);
        return $product;
    }

    private function quoteAcceptingAdd(MagentoProduct $product, callable $requestAssertion): Quote&MockObject
    {
        $shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $quote = $this->createMock(Quote::class);
        $quote->expects($this->once())->method('addProduct')->with(
            $product,
            $this->callback($requestAssertion)
        )->willReturn(true);
        $quote->method('getAllVisibleItems')->willReturn([]);
        $quote->method('getBillingAddress')->willReturn($this->createMock(QuoteAddress::class));
        $quote->method('getShippingAddress')->willReturn($shippingAddress);
        return $quote;
    }

    public function testAddToCartResolvesCustomOptionTitleToValueIdCaseInsensitively(): void
    {
        $product = $this->productWithCustomOption(301, 'Alessi 9090', 175.0, 'ALESSI-9090', $this->sizeCustomOption());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $quote = $this->quoteAcceptingAdd(
            $product,
            static fn (DataObject $request): bool => $request->getData('options') === [7 => '12']
        );

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['ALESSI-9090' => true]),
        ]);

        $storefront->addToCart($this->context(), '301', 1, ['size' => '3 cup']);
    }

    public function testAddToCartThrowsNotOfferedForAnOptionTitleTheProductDoesNotOffer(): void
    {
        $product = $this->productWithCustomOption(301, 'Alessi 9090', 175.0, 'ALESSI-9090', $this->sizeCustomOption());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['ALESSI-9090' => true]),
        ]);

        $this->expectException(NotOffered::class);
        $this->expectExceptionMessage('Alessi 9090 has no option Colour');

        $storefront->addToCart($this->context(), '301', 1, ['Colour' => 'Chrome']);
    }

    public function testAddToCartThrowsNotOfferedForAValueTheOptionDoesNotOffer(): void
    {
        $product = $this->productWithCustomOption(301, 'Alessi 9090', 175.0, 'ALESSI-9090', $this->sizeCustomOption());

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($this->createMock(Quote::class));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['ALESSI-9090' => true]),
        ]);

        $this->expectException(NotOffered::class);
        $this->expectExceptionMessage('Alessi 9090 has no value 5 CUP for Size');

        $storefront->addToCart($this->context(), '301', 1, ['Size' => '5 CUP']);
    }

    public function testAddToCartPassesRawTextForAFieldTypeCustomOption(): void
    {
        $fieldOption = $this->createMock(ProductCustomOptionInterface::class);
        $fieldOption->method('getOptionId')->willReturn(4);
        $fieldOption->method('getTitle')->willReturn('Engraving Text');
        $fieldOption->method('getType')->willReturn('field');
        $fieldOption->method('getValues')->willReturn([]);

        $product = $this->productWithCustomOption(302, 'Engraved Mug', 25.0, 'MUG-1', $fieldOption);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $quote = $this->quoteAcceptingAdd(
            $product,
            static fn (DataObject $request): bool => $request->getData('options') === [4 => 'World\'s Best Dad']
        );

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['MUG-1' => true]),
        ]);

        $storefront->addToCart($this->context(), '302', 1, ['Engraving Text' => 'World\'s Best Dad']);
    }

    private function quoteReportingError(QuoteItem $item, string $errorText): Quote&MockObject
    {
        $error = $this->createMock(MessageInterface::class);
        $error->method('getText')->willReturn($errorText);

        $quote = $this->createMock(Quote::class);
        $quote->method('addProduct')->willReturn($item);
        $quote->method('getAllVisibleItems')->willReturn([$item]);
        $quote->method('getBillingAddress')->willReturn($this->createMock(QuoteAddress::class));
        $quote->method('getShippingAddress')->willReturn($this->createMock(QuoteAddress::class));
        $quote->method('__call')->willReturnCallback(
            static fn (string $method): mixed => $method === 'getHasError' ? true : null
        );
        $quote->method('getErrors')->willReturn([$error]);
        return $quote;
    }

    public function testAddToCartThrowsNotOfferedAndSkipsSaveWhenTheQuoteReportsAnError(): void
    {
        $product = $this->magentoProduct(301, 'Solo Item', 12.0);
        $product->method('getSku')->willReturn('SOLO-1');

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $configurableResource = $this->createMock(ConfigurableResource::class);
        $configurableResource->method('getParentIdsByChild')->willReturn([]);

        $item = $this->createMock(QuoteItem::class);
        $item->method('getId')->willReturn(null);
        $item->method('getMessage')->willReturn(['The requested qty is not available']);

        $quote = $this->quoteReportingError($item, 'Some of the products cannot be ordered in requested quantity.');
        $quote->expects($this->once())->method('deleteItem')->with($item);

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);
        $cartRepository->expects($this->never())->method('save');

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'configurableResource' => $configurableResource,
            'cartRepository' => $cartRepository,
            'salability' => $this->salabilityBySku(['SOLO-1' => true]),
        ]);

        $this->expectException(NotOffered::class);
        $this->expectExceptionMessage('The requested qty is not available');

        $storefront->addToCart($this->context(), '301', 1);
    }

    public function testUpdateCartItemRestoresTheQuantityAndSkipsSaveWhenTheQuoteReportsAnError(): void
    {
        $product = $this->magentoProduct(301, 'Solo Item', 12.0);

        $quantities = [];
        $item = $this->createMock(QuoteItem::class);
        $item->method('getProductType')->willReturn('simple');
        $item->method('getProduct')->willReturn($product);
        $item->method('getQty')->willReturn(2.0);
        $item->method('getMessage')->willReturn([]);
        $item->method('setQty')->willReturnCallback(
            static function (float $qty) use ($item, &$quantities): QuoteItem {
                $quantities[] = $qty;
                return $item;
            }
        );

        $quote = $this->quoteReportingError($item, 'Some of the products are out of stock.');

        $cartRepository = $this->createMock(CartRepositoryInterface::class);
        $cartRepository->method('get')->willReturn($quote);
        $cartRepository->expects($this->never())->method('save');

        $storefront = $this->buildStorefront(['cartRepository' => $cartRepository]);

        try {
            $storefront->updateCartItem($this->context(), '301', 5);
            $this->fail('Expected NotOffered was not thrown.');
        } catch (NotOffered $exception) {
            $this->assertSame('Some of the products are out of stock.', $exception->getMessage());
        }

        $this->assertSame([5.0, 2.0], $quantities);
    }

    public function testGetProductDetailsPreselectsTheVariantOptionsInTheFamilyUrl(): void
    {
        $child = $this->magentoProduct(1001, 'Variant Oak', 10.0);
        $child->method('getData')->willReturnCallback(static function (string $key) {
            return $key === 'colour' ? '42' : null;
        });

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getConfigurableAttributesAsArray')->willReturn([
            [
                'attribute_id' => '93',
                'attribute_code' => 'colour',
                'label' => 'Colour',
                'options' => [['value' => '42', 'label' => 'Oak']],
            ],
        ]);
        $typeInstance->method('getUsedProducts')->willReturn([$child]);

        $parent = $this->createMock(MagentoProduct::class);
        $parent->method('getId')->willReturn(900);
        $parent->method('getName')->willReturn('Configurable Parent');
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getPriceInfo')->willReturn($this->priceInfo(10.0));
        $parent->method('getProductUrl')->willReturn('https://example.com/configurable-parent.html');
        $parent->method('getOptions')->willReturn([]);
        $parent->method('getAttributeText')->willReturn(false);
        $parent->method('getTypeInstance')->willReturn($typeInstance);
        $parent->method('getWebsiteIds')->willReturn([1]);
        $parent->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $parent->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $parent->method('getAttributes')->willReturn([]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($parent);

        $storefront = $this->buildStorefront(['productRepository' => $productRepository]);

        $details = $storefront->getProductDetails($this->context(), '900');

        $this->assertNotNull($details);
        $variants = $details->getVariants();
        $this->assertCount(1, $variants);
        $this->assertSame(
            'https://example.com/configurable-parent.html?colour=42',
            $variants[0]->getUrl()
        );
    }

    public function testGetProductDetailsGivesEveryVariantTheFamilyUrl(): void
    {
        $child = $this->magentoProduct(1001, 'Variant Oak', 10.0);
        $child->method('getProductUrl')->willReturn('https://example.com/catalog/product/view/id/1001/s/variant-oak/');

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getConfigurableAttributesAsArray')->willReturn([]);
        $typeInstance->method('getUsedProducts')->willReturn([$child]);

        $parent = $this->createMock(MagentoProduct::class);
        $parent->method('getId')->willReturn(900);
        $parent->method('getName')->willReturn('Configurable Parent');
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getPriceInfo')->willReturn($this->priceInfo(10.0));
        $parent->method('getProductUrl')->willReturn('https://example.com/configurable-parent.html');
        $parent->method('getOptions')->willReturn([]);
        $parent->method('getAttributeText')->willReturn(false);
        $parent->method('getTypeInstance')->willReturn($typeInstance);
        $parent->method('getWebsiteIds')->willReturn([1]);
        $parent->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $parent->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $parent->method('getAttributes')->willReturn([]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($parent);

        $storefront = $this->buildStorefront(['productRepository' => $productRepository]);

        $details = $storefront->getProductDetails($this->context(), '900');

        $this->assertNotNull($details);
        $variants = $details->getVariants();
        $this->assertCount(1, $variants);
        $this->assertSame('https://example.com/configurable-parent.html', $variants[0]->getUrl());
    }

    public function testGetProductDetailsSetsNoteWhenVariantsAreCapped(): void
    {
        $children = [];
        for ($i = 1; $i <= 61; $i++) {
            $children[] = $this->magentoProduct(1000 + $i, 'Variant ' . $i, (float)$i);
        }

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getConfigurableAttributesAsArray')->willReturn([]);
        $typeInstance->method('getUsedProducts')->willReturn($children);

        $parent = $this->createMock(MagentoProduct::class);
        $parent->method('getId')->willReturn(900);
        $parent->method('getName')->willReturn('Configurable Parent');
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getPriceInfo')->willReturn($this->priceInfo(1.0));
        $parent->method('getProductUrl')->willReturn(null);
        $parent->method('getOptions')->willReturn([]);
        $parent->method('getAttributeText')->willReturn(false);
        $parent->method('getTypeInstance')->willReturn($typeInstance);
        $parent->method('getWebsiteIds')->willReturn([1]);
        $parent->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $parent->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $parent->method('getAttributes')->willReturn([]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($parent);

        $storefront = $this->buildStorefront(['productRepository' => $productRepository]);

        $details = $storefront->getProductDetails($this->context(), '900');

        $this->assertNotNull($details);
        $this->assertCount(60, $details->getVariants());
        $this->assertSame(
            'More variants exist; ask about a size or colour to narrow the list.',
            $details->getNote()
        );
    }

    public function testGetProductDetailsSlicesUsedProductsBeforeMappingAndBulkResolvesSalability(): void
    {
        $children = [];
        for ($i = 1; $i <= 70; $i++) {
            $children[] = $this->magentoProduct(2000 + $i, 'Variant ' . $i, (float)$i);
        }

        $typeInstance = $this->createMock(Configurable::class);
        $typeInstance->method('getConfigurableAttributesAsArray')->willReturn([]);
        $typeInstance->method('getUsedProducts')->willReturn($children);

        $parent = $this->createMock(MagentoProduct::class);
        $parent->method('getId')->willReturn(1900);
        $parent->method('getName')->willReturn('Configurable Parent');
        $parent->method('getTypeId')->willReturn(Configurable::TYPE_CODE);
        $parent->method('getPriceInfo')->willReturn($this->priceInfo(1.0));
        $parent->method('getProductUrl')->willReturn(null);
        $parent->method('getOptions')->willReturn([]);
        $parent->method('getAttributeText')->willReturn(false);
        $parent->method('getTypeInstance')->willReturn($typeInstance);
        $parent->method('getWebsiteIds')->willReturn([1]);
        $parent->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $parent->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $parent->method('getAttributes')->willReturn([]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($parent);

        $urlFinder = $this->createMock(UrlFinderInterface::class);
        $urlFinder->expects($this->once())->method('findAllByData')->with(
            $this->callback(static fn (array $data): bool => count($data[UrlRewrite::ENTITY_ID]) === 61)
        )->willReturn([]);

        $productMapper = new ProductMapper(
            $this->storeManager(),
            $this->productImageUrl(),
            $this->helperImageUrl(),
            $this->salability(true),
            new CoreOptions(),
            $urlFinder
        );

        $areProductsSalable = $this->createMock(AreProductsSalableInterface::class);
        $areProductsSalable->expects($this->once())->method('execute')->with(
            $this->callback(static fn (array $skus): bool => count($skus) === 61)
        )->willReturn([]);
        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(1);
        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturn($stock);
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnMap([
            [StockResolverInterface::class, $stockResolver],
            [AreProductsSalableInterface::class, $areProductsSalable],
        ]);
        $salability = new Salability(
            $objectManager,
            $this->createMock(StockRegistryInterface::class),
            $this->storeManager()
        );

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'productMapper' => $productMapper,
            'salability' => $salability,
        ]);

        $details = $storefront->getProductDetails($this->context(), '1900');

        $this->assertNotNull($details);
        $this->assertCount(60, $details->getVariants());
    }

    public function testGetProductDetailsReturnsNullForAProductOutsideTheAllowlist(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($this->detailedProduct(700, [7]));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([10], [$this->category(7, '1/2/5/7')]),
        ]);

        $this->assertNull($storefront->getProductDetails($this->context(), '700'));
    }

    public function testGetProductDetailsReturnsNullForAProductOutsideTheCurrentWebsite(): void
    {
        $product = $this->createMock(MagentoProduct::class);
        $product->method('getId')->willReturn(702);
        $product->method('getStatus')->willReturn(Status::STATUS_ENABLED);
        $product->method('getVisibility')->willReturn(Visibility::VISIBILITY_BOTH);
        $product->method('getCategoryIds')->willReturn([55]);
        $product->method('getWebsiteIds')->willReturn([2]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($product);

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([10], [$this->category(55, '1/2/10/55')]),
        ]);

        $this->assertNull($storefront->getProductDetails($this->context(), '702'));
    }

    public function testGetProductDetailsReturnsAProductUnderAnAllowedCategory(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($this->detailedProduct(701, [55]));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([10], [$this->category(55, '1/2/10/55')]),
        ]);

        $details = $storefront->getProductDetails($this->context(), '701');

        $this->assertNotNull($details);
        $this->assertSame('701', $details->getProductId());
    }

    public function testGetProductDetailsIgnoresTheAllowlistWhenItIsEmpty(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getById')->willReturn($this->detailedProduct(702, [7]));

        $collectionFactory = $this->createMock(CategoryCollectionFactory::class);
        $collectionFactory->expects($this->never())->method('create');

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([], [], $collectionFactory),
        ]);

        $this->assertNotNull($storefront->getProductDetails($this->context(), '702'));
    }

    public function testGetProductDetailsLoadsBySkuWhenTheIdIsNotNumeric(): void
    {
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->never())->method('getById');
        $productRepository->method('get')
            ->with('24-MB01', false, $this->context()->storeId)
            ->willReturn($this->detailedProduct(703, [55]));

        $storefront = $this->buildStorefront([
            'productRepository' => $productRepository,
            'allowedCategories' => $this->allowedCategories([], []),
        ]);

        $details = $storefront->getProductDetails($this->context(), '24-MB01');

        $this->assertNotNull($details);
        $this->assertSame('703', $details->getProductId());
    }

    public function testGetOrdersThrowsSignInRequiredForGuest(): void
    {
        $storefront = $this->buildStorefront();

        $this->expectException(SignInRequired::class);

        $storefront->getOrders($this->context(null), 10);
    }

    public function testGetOrderThrowsSignInRequiredForGuest(): void
    {
        $storefront = $this->buildStorefront();

        $this->expectException(SignInRequired::class);

        $storefront->getOrder($this->context(null), '100000001');
    }

    private function orderItem(
        string $sku,
        string $name,
        float $price,
        int $fallbackProductId
    ): SalesOrderItem&MockObject {

        $item = $this->createMock(SalesOrderItem::class);
        $item->method('getProductOptions')->willReturn(['simple_sku' => $sku]);
        $item->method('getName')->willReturn($name);
        $item->method('getQtyOrdered')->willReturn(1);
        $item->method('getPrice')->willReturn($price);
        $item->method('getProductId')->willReturn($fallbackProductId);
        return $item;
    }

    private function salesOrder(string $incrementId, array $items): SalesOrder&MockObject
    {
        $order = $this->createMock(SalesOrder::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getState')->willReturn(SalesOrder::STATE_PROCESSING);
        $order->method('getStatus')->willReturn('processing');
        $order->method('getCreatedAt')->willReturn('2026-01-01 00:00:00');
        $order->method('getGrandTotal')->willReturn(50.0);
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getAllVisibleItems')->willReturn($items);
        return $order;
    }

    public function testGetOrdersResolvesSimpleSkusInOneBulkRepositoryCall(): void
    {
        $itemOne = $this->orderItem('SHIRT-RED-M', 'Red Shirt', 25.0, 900);
        $itemTwo = $this->orderItem('SHIRT-BLUE-L', 'Blue Shirt', 30.0, 901);

        $orderOne = $this->salesOrder('100000001', [$itemOne]);
        $orderTwo = $this->salesOrder('100000002', [$itemTwo]);

        $listResult = $this->createMock(OrderSearchResultInterface::class);
        $listResult->method('getItems')->willReturn([$orderOne, $orderTwo]);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($listResult);

        $simpleOne = $this->magentoProduct(910, 'Red Shirt Simple', 25.0);
        $simpleOne->method('getSku')->willReturn('SHIRT-RED-M');
        $simpleTwo = $this->magentoProduct(911, 'Blue Shirt Simple', 30.0);
        $simpleTwo->method('getSku')->willReturn('SHIRT-BLUE-L');

        $productResults = $this->createMock(ProductSearchResultsInterface::class);
        $productResults->method('getItems')->willReturn([$simpleOne, $simpleTwo]);

        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->expects($this->once())->method('getList')->willReturn($productResults);
        $productRepository->expects($this->never())->method('get');

        $orderStatusMapper = $this->createMock(OrderStatusMapperInterface::class);
        $orderStatusMapper->method('map')->willReturn('processing');

        $storefront = $this->buildStorefront([
            'orderRepository' => $orderRepository,
            'productRepository' => $productRepository,
            'orderStatusMapper' => $orderStatusMapper,
        ]);

        $orders = $storefront->getOrders($this->context(42), 10);

        $this->assertCount(2, $orders);
        $this->assertSame('910', $orders[0]->getItems()[0]->getProductId());
        $this->assertSame('911', $orders[1]->getItems()[0]->getProductId());
    }

    public function testGetOrdersFallsBackToOrderItemProductIdWhenSkuUnresolved(): void
    {
        $item = $this->orderItem('MISSING-SKU', 'Ghost Item', 15.0, 902);
        $order = $this->salesOrder('100000003', [$item]);

        $listResult = $this->createMock(OrderSearchResultInterface::class);
        $listResult->method('getItems')->willReturn([$order]);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($listResult);

        $productResults = $this->createMock(ProductSearchResultsInterface::class);
        $productResults->method('getItems')->willReturn([]);
        $productRepository = $this->createMock(ProductRepositoryInterface::class);
        $productRepository->method('getList')->willReturn($productResults);
        $productRepository->expects($this->never())->method('get');

        $orderStatusMapper = $this->createMock(OrderStatusMapperInterface::class);
        $orderStatusMapper->method('map')->willReturn('processing');

        $storefront = $this->buildStorefront([
            'orderRepository' => $orderRepository,
            'productRepository' => $productRepository,
            'orderStatusMapper' => $orderStatusMapper,
        ]);

        $orders = $storefront->getOrders($this->context(42), 10);

        $this->assertSame('902', $orders[0]->getItems()[0]->getProductId());
    }

    public function testGetOrdersResolvesTrackingInOneBulkCallAndPassesItToTheStatusMapper(): void
    {
        $orderOne = $this->salesOrder('100000001', []);
        $orderOne->method('getId')->willReturn(1);
        $orderTwo = $this->salesOrder('100000002', []);
        $orderTwo->method('getId')->willReturn(2);

        $listResult = $this->createMock(OrderSearchResultInterface::class);
        $listResult->method('getItems')->willReturn([$orderOne, $orderTwo]);
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('getList')->willReturn($listResult);

        $track = $this->createMock(\Magento\Sales\Api\Data\ShipmentTrackInterface::class);
        $track->method('getOrderId')->willReturn(1);
        $trackResults = $this->createMock(ShipmentTrackSearchResultInterface::class);
        $trackResults->method('getItems')->willReturn([$track]);
        $shipmentTrackRepository = $this->createMock(ShipmentTrackRepositoryInterface::class);
        $shipmentTrackRepository->expects($this->once())->method('getList')->willReturn($trackResults);

        $shipmentResults = $this->createMock(ShipmentSearchResultInterface::class);
        $shipmentResults->method('getItems')->willReturn([]);
        $shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $shipmentRepository->expects($this->once())->method('getList')->willReturn($shipmentResults);

        $calls = [];
        $orderStatusMapper = $this->createMock(OrderStatusMapperInterface::class);
        $orderStatusMapper->method('map')->willReturnCallback(
            function (SalesOrder $order, bool $hasTracking) use (&$calls): string {
                $calls[] = $hasTracking;
                return 'processing';
            }
        );

        $storefront = $this->buildStorefront([
            'orderRepository' => $orderRepository,
            'shipmentTrackRepository' => $shipmentTrackRepository,
            'shipmentRepository' => $shipmentRepository,
            'orderStatusMapper' => $orderStatusMapper,
        ]);

        $storefront->getOrders($this->context(42), 10);

        $this->assertSame([true, false], $calls);
    }
}
