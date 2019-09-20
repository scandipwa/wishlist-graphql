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
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Wishlist\Model\ResourceModel\Wishlist as WishlistResourceModel;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\WishlistFactory;

/**
 * Fetches the Wishlist data according to the GraphQL schema
 */
class WishlistResolver implements ResolverInterface
{
    /**
     * @var WishlistResourceModel
     */
    private $wishlistResource;

    /**
     * @var WishlistFactory
     */
    private $wishlistFactory;

    /**
     * @var Configurable
     */
    private $configurable;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @param WishlistResourceModel $wishlistResource
     * @param WishlistFactory $wishlistFactory
     */
    public function __construct(
        WishlistResourceModel $wishlistResource,
        WishlistFactory $wishlistFactory,
        Configurable $configurable,
        ProductFactory $productFactory
    )
    {
        $this->wishlistResource = $wishlistResource;
        $this->wishlistFactory = $wishlistFactory;
        $this->configurable = $configurable;
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
        $customerId = $context->getUserId();

        /** @var Wishlist $wishlist */
        $wishlist = $this->wishlistFactory->create();
        $this->wishlistResource->load($wishlist, $customerId, 'customer_id');

        if (null === $wishlist->getId()) {
            return [
                'model' => $wishlist,
            ];
        }

        $itemsData = [];
        foreach ($wishlist->getItemCollection() as $item) {
            $product = $item->getProduct();
            $parentIds = $this->configurable->getParentIdsByChild($product->getId());

            print_r($product->getData());

            if (count($parentIds)) {
                $parentProduct = $this->productFactory->create()->load(reset($parentIds));
                $itemsData[] = array_merge(
                    $item->getData(),
                    [
                        'product' => array_merge(
                            $parentProduct->getData(),
                            ['model' => $parentProduct]
                        ),
                        'sku' => $item->getSku()
                    ]
                );
            } else {
                $itemsData[] = array_merge(
                    $item->getData(),
                    ['product' =>
                        array_merge(
                            $product->getData(),
                            ['model' => $product]
                        )
                    ]
                );
            }
        }

        exit;

        return [
            'sharing_code' => $wishlist->getSharingCode(),
            'updated_at' => $wishlist->getUpdatedAt(),
            'items_count' => $wishlist->getItemsCount(),
            'name' => $wishlist->getName(),
            'items' => $itemsData,
            'model' => $wishlist,
        ];
    }
}
