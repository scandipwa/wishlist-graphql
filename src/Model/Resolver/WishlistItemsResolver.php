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

use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product\CollectionProcessorInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\ResourceModel\Item\Collection as WishlistItemCollection;
use Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory as WishlistItemCollectionFactory;
use Magento\Wishlist\Model\Wishlist;
use ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use ScandiPWA\Performance\Model\Resolver\ResolveInfoFieldsTrait;

/**
 * Fetches the Wish-list Items data according to the GraphQL schema
 */
class WishlistItemsResolver implements ResolverInterface
{
    use ResolveInfoFieldsTrait;

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
     * @param WishlistItemCollectionFactory $wishlistItemsFactory
     * @param StoreManagerInterface $storeManager
     * @param ProductFactory $productFactory
     * @param DataPostProcessor $productPostProcessor
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param CollectionProcessorInterface $collectionProcessor
     * @param ProductCollectionFactory $collectionFactory
     */
    public function __construct(
        WishlistItemCollectionFactory $wishlistItemsFactory,
        StoreManagerInterface $storeManager,
        ProductFactory $productFactory,
        DataPostProcessor $productPostProcessor,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        CollectionProcessorInterface $collectionProcessor,
        ProductCollectionFactory $collectionFactory
    ) {
        $this->wishlistItemsFactory = $wishlistItemsFactory;
        $this->storeManager = $storeManager;
        $this->productFactory = $productFactory;
        $this->productPostProcessor = $productPostProcessor;
        $this->collectionProcessor = $collectionProcessor;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->collectionFactory = $collectionFactory;
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

        $data = [];
        foreach ($wishlistItems as $wishlistItem) {
            $wishlistItemId = $wishlistItem->getId();
            $wishlistProductId = $itemProductIds[$wishlistItemId];
            $itemProduct = $wishlistProducts[$wishlistProductId];

            $data[] = [
                'id' => $wishlistItemId,
                'qty' => $wishlistItem->getData('qty'),
                'sku' => $this->getWishListItemSku($wishlistItem),
                'description' => $wishlistItem->getDescription(),
                'added_at' => $wishlistItem->getAddedAt(),
                'model' => $wishlistItem,
                'product' => $itemProduct
            ];
        }

        return $data;
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

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $variantId = $wishlistItem->getOptionByCode('simple_product')->getValue();
            $childProduct = $this->productFactory->create()->load($variantId);

            return $childProduct->getSku();
        }

        return $product->getSku();
    }
}
