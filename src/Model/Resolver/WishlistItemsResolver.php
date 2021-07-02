<?php

/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/wishlist-graphql
 * @link    https://github.com/scandipwa/wishlist-graphql
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\Resolver;

use Magento\Bundle\Helper\Catalog\Product\Configuration as BundleOptions;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Helper\Product\Configuration as ProductOptions;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\CollectionProcessorInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Downloadable\Helper\Catalog\Product\Configuration as DownloadableOptions;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\ObjectManagerInterface;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\GroupedProduct\Pricing\Price\ConfiguredPrice as GroupedPrice;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\ResourceModel\Item\Collection as WishlistItemCollection;
use Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory as WishlistItemCollectionFactory;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Pricing\ConfiguredPrice\ConfigurableProduct as ConfigurablePrice;
use Magento\Wishlist\Pricing\ConfiguredPrice\Downloadable as DownloadablePrice;
use ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor;
use ScandiPWA\Performance\Model\Resolver\ResolveInfoFieldsTrait;

/**
 * Fetches the Wish-list Items data according to the GraphQL schema
 */
class WishlistItemsResolver implements ResolverInterface
{
    use ResolveInfoFieldsTrait;

    const PRICE_CALCULATION_MAP = [
        GroupedType::TYPE_CODE => GroupedPrice::class,
        DownloadableType::TYPE_DOWNLOADABLE => DownloadablePrice::class,
        ConfigurableType::TYPE_CODE => ConfigurablePrice::class
    ];

    /**
     * @var WishlistItemCollectionFactory
     */
    protected $wishlistItemsFactory;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var ProductFactory
     */
    protected $productFactory;

    /**
     * @var DataPostProcessor
     */
    protected $productPostProcessor;

    /**
     * @var CollectionProcessorInterface
     */
    protected $collectionProcessor;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var ProductCollectionFactory
     */
    protected $collectionFactory;

    /**
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var TaxCalculationInterface
     */
    protected $taxCalculator;

    /**
     * @var ProductOptions
     */
    protected $productOptions;

    /**
     * @var BundleOptions
     */
    protected $bundleOptions;

    /**
     * @var DownloadableOptions
     */
    protected $downloadableOptions;

    /**
     * @var array
     */
    protected $taxRateCache = [];

    /**
     * WishlistItemsResolver constructor.
     * @param WishlistItemCollectionFactory $wishlistItemsFactory
     * @param StoreManagerInterface $storeManager
     * @param ProductFactory $productFactory
     * @param DataPostProcessor $productPostProcessor
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CollectionProcessorInterface $collectionProcessor
     * @param ProductCollectionFactory $collectionFactory
     * @param ObjectManagerInterface $objectManager
     * @param TaxCalculationInterface $taxCalculator
     * @param ProductOptions $productOptions
     * @param BundleOptions $bundleOptions
     * @param DownloadableOptions $downloadableOptions
     */
    public function __construct(
        WishlistItemCollectionFactory $wishlistItemsFactory,
        StoreManagerInterface $storeManager,
        ProductFactory $productFactory,
        DataPostProcessor $productPostProcessor,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CollectionProcessorInterface $collectionProcessor,
        ProductCollectionFactory $collectionFactory,
        ObjectManagerInterface $objectManager,
        TaxCalculationInterface $taxCalculator,
        ProductOptions $productOptions,
        BundleOptions $bundleOptions,
        DownloadableOptions $downloadableOptions
    ) {
        $this->wishlistItemsFactory = $wishlistItemsFactory;
        $this->storeManager = $storeManager;
        $this->productFactory = $productFactory;
        $this->productPostProcessor = $productPostProcessor;
        $this->collectionProcessor = $collectionProcessor;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->collectionFactory = $collectionFactory;
        $this->objectManager = $objectManager;
        $this->taxCalculator = $taxCalculator;
        $this->productOptions = $productOptions;
        $this->bundleOptions = $bundleOptions;
        $this->downloadableOptions = $downloadableOptions;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($value['model'])) {
            return null;
        }

        /** @var Wishlist $wishlist */
        $wishlist = $value['model'];
        $wishlistItems = $this->getWishListItems($wishlist);
        $itemProductIds = [];

        /** @var Item $item */
        foreach ($wishlistItems as $item) {
            $itemProductIds[$item->getId()] = $item->getProductId();
        }

        $wishlistProducts = $this->getWishlistProducts(
            $itemProductIds,
            $info
        );

        $customerId = $wishlist->getCustomerId();
        $storeId = $wishlist->getStore()->getId();

        $data = [];

        /** @var Item $wishlistItem */
        foreach ($wishlistItems as $wishlistItem) {
            $wishlistItemId = $wishlistItem->getId();
            $wishlistProductId = $itemProductIds[$wishlistItemId];
            $itemProduct = $wishlistProducts[$wishlistProductId];
            $type = $itemProduct['type_id'];
            $qty = $wishlistItem->getData('qty');

            $price = $this->getItemPrice($wishlistItem, $type, $qty);
            $priceWithoutTax = $this->getPriceWithoutTax(
                $price,
                $itemProduct['model']->getData('tax_class_id'),
                $storeId,
                $customerId
            );

            $buyRequestOption = $wishlistItem->getOptionByCode('info_buyRequest');
            $options = $this->getItemOptions($wishlistItem, $type);

            $product = $wishlistItem->getProduct();

            $productPriceIncTax = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue();
            $productPriceExcTax = $product->getPriceInfo()->getPrice('final_price')->getMinimalPrice()->getValue('tax');

            $data[] = [
                'id' => $wishlistItemId,
                'qty' => $qty,
                'sku' => $this->getWishListItemSku($wishlistItem),
                'price' => $productPriceIncTax,
                'price_without_tax' => $productPriceExcTax,
                'buy_request' => $buyRequestOption->getValue() ?? '',
                'description' => $wishlistItem->getDescription(),
                'added_at' => $wishlistItem->getAddedAt(),
                'model' => $wishlistItem,
                'product' => $itemProduct,
                'options' => $options
            ];
        }

        return $data;
    }

    /**
     * @param $item
     * @param $type
     * @return array
     */
    protected function getItemOptions($item, $type)
    {
        $options = [];
        switch ($type) {
            case BundleType::TYPE_CODE:
                $options = $this->bundleOptions->getOptions($item);
                break;
            case DownloadableType::TYPE_DOWNLOADABLE:
                $options = $this->downloadableOptions->getOptions($item);
                break;
            default:
                return $this->productOptions->getOptions($item);
        }

        $output = [];
        foreach ($options as $option) {
            $value = is_array($option['value']) ?
                     join(', ', $option['value']) :
                     $option['value'];

            $output[] = [
                'label' => $option['label'],
                'value' => strip_tags($value)
            ];
        }
        return $output;
    }

    /**
     * @param Item $item
     * @param string $type
     * @param integer $qty
     * @return float
     */
    protected function getItemPrice($item, $type, $qty)
    {
        $controller = self::PRICE_CALCULATION_MAP[$type] ?? null;
        if ($controller === null) {
            return null;
        }

        $configuredPrice = $this->objectManager->create($controller, [
            'saleableItem' => $item->getProduct(),
            'quantity' => $qty
        ]);
        $configuredPrice->setItem($item);

        return $configuredPrice->getValue();
    }

    /**
     * @param $price
     * @param $taxClassId
     * @param $storeId
     * @param $customerId
     * @return float
     */
    protected function getPriceWithoutTax($price, $taxClassId, $storeId, $customerId)
    {
        if ($price === null) {
            return null;
        }

        // Loads rate from cache
        if (isset($this->taxRateCache[$taxClassId])) {
            return $price * $this->taxRateCache[$taxClassId];
        }

        // Calculates new rate
        $rate = 1 - $this->taxCalculator->getCalculatedRate($taxClassId, $customerId, $storeId);

        // Stores into cache
        $this->taxRateCache[$taxClassId] = $rate;

        return $price * $this->taxRateCache[$taxClassId];
    }

    /**
     * Collect wishlist item products
     *
     * @param array $itemProductIds
     * @param ResolveInfo $info
     * @return array
     */
    protected function getWishlistProducts(
        array $itemProductIds,
        ResolveInfo $info
    ) {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        $collection->addIdFilter(array_values($itemProductIds));

        $this->collectionProcessor->process(
            $collection,
            $this->searchCriteriaBuilder->create(),
            $this->getFieldsFromProductInfo($info, 'items/product')
        );

        $items = $collection->getItems();

        return $this->productPostProcessor->process(
            $items,
            'items/product',
            $info
        );
    }

    /**
     * Get wish-list items
     *
     * @param Wishlist $wishlist
     * @return Item[]
     */
    protected function getWishListItems(
        Wishlist $wishlist
    ): array {
        /** @var WishlistItemCollection $collection */
        $collection = $this->wishlistItemsFactory->create();
        $collection
            ->addWishlistFilter($wishlist)
            ->addStoreFilter(array_map(function (StoreInterface $store) {
                return $store->getId();
            }, $this->storeManager->getStores()))
            ->setVisibilityFilter();

        return $collection->getItems();
    }

    /**
     * Get wish-list item's sku
     *
     * @param Item $wishlistItem
     * @return string
     * @throws LocalizedException
     */
    protected function getWishListItemSku(
        Item $wishlistItem
    ): string {
        $product = $wishlistItem->getProduct();

        if ($product->getTypeId() === ConfigurableType::TYPE_CODE) {
            $productOption = $wishlistItem->getOptionByCode('simple_product');

            if ($productOption) {
                $variantId = $productOption->getValue();
                $childProduct = $this->productFactory->create()->load($variantId);
                return $childProduct->getSku();
            }
        }

        return $product->getSku();
    }
}
