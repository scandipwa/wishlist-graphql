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
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Wishlist\Model\WishlistFactory;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Wishlist\Model\ItemFactory;

/**
 * Class AddWishlistForCustomer
 * @package ScandiPWA\WishlistGraphQl\Model\Resolver
 */
class RemoveProductFromWishlist implements ResolverInterface
{
    /**
     * @var CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;

    /**
     * @var WishlistItemFactory
     */
    protected $wishlistItemFactory;

    /**
     * AddWishlistForCustomer constructor.
     * @param CustomerRepositoryInterface $customerRepository
     * @param WishlistFactory $wishlistFactory
     * @param WishlistItemFactory $wishlistItemFactory
     */
    public function __construct(
        CustomerRepositoryInterface $customerRepository,
        WishlistFactory $wishlistFactory,
        ItemFactory $wishlistItemFactory
    ) {
        $this->customerRepository = $customerRepository;
        $this->wishlistFactory = $wishlistFactory;
        $this->wishlistItemFactory = $wishlistItemFactory;
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
        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('There was an issue with authorization'));
        }

        if (!isset($args['itemId'])) {
            throw new GraphQlInputException(__('Please specify a valid wishlist item'));
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

        try {
            $item->delete();
            $wishlist->save();
        } catch (Exception $e) {
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to delete item'));
        }

        return true;
    }
}