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

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Wishlist\Model\WishlistFactory;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;

/**
 * Class AddWishlistForCustomer
 * @package ScandiPWA\WishlistGraphQl\Model\Resolver
 */
class AddProductToWishlist implements ResolverInterface
{
    /**
     * @var ProductRepositoryInterface
     */
    protected $productRepository;

    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;

    /**
     * AddWishlistForCustomer constructor.
     * @param ProductRepositoryInterface $productRepository
     * @param CustomerRepositoryInterface $customerRepository
     * @param WishlistFactory $wishlistFactory
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        CustomerRepositoryInterface $customerRepository,
        WishlistFactory $wishlistFactory
    ) {
        $this->productRepository = $productRepository;
        $this->customerRepository = $customerRepository;
        $this->wishlistFactory = $wishlistFactory;
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
        if ($customerId === null || $customerId === 0) {
            throw new GraphQlAuthorizationException(__('Authorization unsuccessful'));
        }

        if (!isset($args['productSku'])) {
            throw new GraphQlInputException(__('Please specify valid product'));
        }

        $product = $this->productRepository->get($args['productSku']);
        if (!$product->isVisibleInCatalog()) {
            throw new GraphQlInputException(__('Please specify valid product'));
        }

        try {
            $wishlist = $this->wishlistFactory->create();
            $wishlist->loadByCustomerId($customerId, true);

            $itemCollection = $wishlist->getItemCollection()
                ->addFieldToFilter('product_id', $product->getId());

            if ($itemCollection->getSize() > 0) {
                throw new GraphQlInputException(__('Product has already been added to wishlist'));
            }

            $wishlistItem = $wishlist->addNewItem($product);
            $wishlist->save();
        } catch (Exception $e) {
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to save wishlist'));
        }

        if ($wishlistItem->getProductId() === null) {
            return [];
        }

        return array_merge(
            $wishlistItem->getData(),
            ['model' => $wishlistItem],
            ['product' =>
                array_merge(
                    $wishlistItem->getProduct()->getData(),
                    ['model' => $product]
                )
            ]);
    }
}