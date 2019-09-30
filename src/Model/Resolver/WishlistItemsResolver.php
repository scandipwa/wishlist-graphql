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
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\ResourceModel\Item\Collection as WishlistItemCollection;
use Magento\Wishlist\Model\ResourceModel\Item\CollectionFactory as WishlistItemCollectionFactory;
use Magento\Wishlist\Model\Item;
use Magento\Wishlist\Model\Wishlist;

/**
 * Fetches the Wishlist Items data according to the GraphQL schema
 */
class WishlistItemsResolver implements ResolverInterface
{
    /**
     * @var WishlistItemCollectionFactory
     */
    private $wishlistItemCollectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @param WishlistItemCollectionFactory $wishlistItemCollectionFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        WishlistItemCollectionFactory $wishlistItemCollectionFactory,
        StoreManagerInterface $storeManager,
        ProductFactory $productFactory
    ) {
        $this->wishlistItemCollectionFactory = $wishlistItemCollectionFactory;
        $this->storeManager = $storeManager;
        $this->productFactory = $productFactory;
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
            throw new LocalizedException(__('Missing key "model" in Wishlist value data'));
        }
        /** @var Wishlist $wishlist */
        $wishlist = $value['model'];

        $wishlistItems = $this->getWishListItems($wishlist);

        $data = [];
        foreach ($wishlistItems as $wishlistItem) {
            $data[] = [
                'id' => $wishlistItem->getId(),
                'qty' => $wishlistItem->getData('qty'),
                'sku' => $this->getWishListItemSku($wishlistItem),
                'description' => $wishlistItem->getDescription(),
                'added_at' => $wishlistItem->getAddedAt(),
                'model' => $wishlistItem,
            ];
        }
        return $data;
    }

    /**
     * Get wishlist items
     *
     * @param Wishlist $wishlist
     * @return Item[]
     */
    private function getWishListItems(Wishlist $wishlist): array
    {
        /** @var WishlistItemCollection $wishlistItemCollection */
        $wishlistItemCollection = $this->wishlistItemCollectionFactory->create();
        $wishlistItemCollection
            ->addWishlistFilter($wishlist)
            ->addStoreFilter(array_map(function (StoreInterface $store) {
                return $store->getId();
            }, $this->storeManager->getStores()))
            ->setVisibilityFilter();
        return $wishlistItemCollection->getItems();
    }

    /**
     * Get wishlist item's sku
     *
     * @return string
     */
    private function getWishListItemSku($wishlistItem): string
    {
        $product = $wishlistItem->getProduct();
        $sku = $product->getSku();

        if ($product->getTypeId() === Configurable::TYPE_CODE) {
            $variantId = $wishlistItem->getOptionByCode('simple_product')->getValue();
            $childProduct = $this->productFactory->create()->load($variantId);
            $sku = $childProduct->getSku();
        }

        return $sku;
    }

}
