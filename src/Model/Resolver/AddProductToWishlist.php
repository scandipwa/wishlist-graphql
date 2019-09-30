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

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Wishlist\Model\WishlistFactory;

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

        ['sku' => $sku] = $args['wishlistItem'];
        $quantity = $args['wishlistItem']['quantity'] ?? 1;
        $description = $args['wishlistItem']['description'] ?? '';
        $productOption = $args['wishlistItem']['product_option'] ?? [];

        if (!isset($sku)) {
            throw new GraphQlInputException(__('Please specify valid product'));
        }

        $product = $this->productRepository->get($sku);
        if (!$product->isVisibleInCatalog()) {
            throw new GraphQlInputException(__('Please specify valid product'));
        }

        try {
            $wishlist = $this->wishlistFactory->create();
            $wishlist->loadByCustomerId($customerId, true);

            $buyRequest = [];
            if ($product->getTypeId() === Configurable::TYPE_CODE) {
                $configurableOptions = $this->getOptionsArray($productOption['extension_attributes']['configurable_item_options']);
                $buyRequest['super_attribute'] = $configurableOptions;
            }

            $wishlistItem = $wishlist->addNewItem($product, $buyRequest);
            $wishlistItem->setDescription($description);
            $wishlistItem->setQty($quantity);

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
            [
                'product' =>
                array_merge(
                    $wishlistItem->getProduct()->getData(),
                    ['model' => $product]
                ),
            ]
        );
    }

    private function getOptionsArray($configurableOptions)
    {
        $optionsArray = [];
        foreach ($configurableOptions as ['option_id' => $id, 'option_value' => $value]) {
            $optionsArray[(string) $id] = (int) $value;
        }

        return $optionsArray;
    }
}
