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

use Magento\Wishlist\Model\ItemFactory;
use Magento\Wishlist\Model\WishlistFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;

/**
 * Class UpdateProductInWishlist
 * @package ScandiPWA\WishlistGraphQl\Model\Resolver
 */
class UpdateWishlistItem implements ResolverInterface
{
    /**
     * @var ItemFactory
     */
    protected $wishlistItemFactory;

    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;

    public function __construct (
        ItemFactory $wishlistItemFactory,
        WishlistFactory $wishlistFactory
    ) {
        $this->wishlistFactory = $wishlistFactory;
        $this->wishlistItemFactory = $wishlistItemFactory;
    }

    /**
     * @inheritDoc
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

        if (!isset($args['itemId'])) {
            throw new GraphQlInputException(__('Please specify a valid wishlist item'));
        }

        if (!(array_key_exists('quantity', $args) || array_key_exists('description', $args))) {
            throw new GraphQlInputException(__('Please specify either quantity or description to update'));
        }


        $item = $this->wishlistItemFactory->create()->load($args['itemId']);

        if (!$item->getId()) {
            throw new GraphQlInputException(__('Please specify a valid wishlist item'));
        }

        $wishlist = $this->wishlistFactory->create();
        $wishlist->loadByCustomerId($customerId);
        if (!$wishlist || $wishlist->getId() !== $item->getWishlistId()) {
            throw new GraphQlNoSuchEntityException(__('Invalid wishlist'));
        }

        if (array_key_exists('quantity', $args)) {
            $item->setQty($args['quantity']);
        }

        if (array_key_exists('description', $args)) {
            $item->setDescription($args['description']);
        }

        try {
            $item->save();
            $wishlist->save();
        } catch (Exception $e) {
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to update wishlist item'));
        }

        return array_merge(
            $item->getData(),
            ['model' => $item]
        );
    }

}
