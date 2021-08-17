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

use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\DataObject;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\GuestCartRepositoryInterface;
use Magento\Quote\Model\Webapi\ParamOverriderCartId;
use Magento\Wishlist\Model\ResourceModel\Wishlist as WishlistResourceModel;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\WishlistFactory;

class MoveWishlistToCart implements ResolverInterface
{
    /**
     * @var ParamOverriderCartId
     */
    protected $overriderCartId;

    /**
     * @var CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * @var CartManagementInterface
     */
    protected $quoteManagement;

    /**
     * @var WishlistFactory
     */
    protected $wishlistFactory;

    /**
     * @var WishlistResourceModel
     */
    protected $wishlistResource;

    /**
     * @var GuestCartRepositoryInterface
     */
    protected $guestCartRepository;
    /**
     * @var StockStatusRepositoryInterface
     */
    protected $stockStatusRepository;

    public function __construct(
        ParamOverriderCartId $overriderCartId,
        CartRepositoryInterface $quoteRepository,
        CartManagementInterface $quoteManagement,
        GuestCartRepositoryInterface $guestCartRepository,
        WishlistFactory $wishlistFactory,
        WishlistResourceModel $wishlistResource,
        StockStatusRepositoryInterface $stockStatusRepository
    ) {
        $this->overriderCartId = $overriderCartId;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->wishlistFactory = $wishlistFactory;
        $this->wishlistResource = $wishlistResource;
        $this->guestCartRepository = $guestCartRepository;
        $this->stockStatusRepository = $stockStatusRepository;
    }

    /**
     * Adds new items from wishlist to cart
     *
     * @param array $wishlistItems
     * @return void
     */
    protected function addItemsToCart(array $wishlistItems, CartInterface $quote): void
    {
        $errors = [];

        foreach ($wishlistItems as $item) {
            $product = $item['product'];

            try {
                $stockStatus = $this->stockStatusRepository->get($product->getId());
                $productStockStatus = $stockStatus->getStockStatus();

                // If product is out of stock outputs error message
                if ($productStockStatus === StockStatusInterface::STATUS_OUT_OF_STOCK) {
                    throw new GraphQlNoSuchEntityException(__('One or more items are out of stock'));
                }

                $data = json_decode($item['buy_request'], true);

                $buyRequest = new DataObject();
                $buyRequest->setData($data);

                $quoteItem = $quote->addProduct($product, $buyRequest);

                if (is_string($quoteItem)) {
                    $msg = trim($quoteItem, '.');
                    $errors[] = $msg . ' for "' . $product->getName() . '"';
                } else {
                    $quoteItem->setQty($item['qty']);
                    $item['item']->delete();
                }
            } catch (\Exception $e) {
                $errors[] = $e->getMessage() . ' for "' . $product->getName() . '"';
            }
        }

        if (count($errors) > 0) {
            throw new GraphQlInputException(__(json_encode($errors)));
        }

        try {
            $this->quoteRepository->save($quote);
        } catch (Exception $e) {
            throw new GraphQlNoSuchEntityException(__('Failed to add items to cart'));
        }
    }

    /**
     * Prepares array with wishlist data
     *
     * @param Wishlist $wishlist
     * @return array
     */
    protected function getWishlistItems(Wishlist $wishlist, bool $shouldDeleteItems): array
    {
        $items = [];
        $itemsCollection = $wishlist->getItemCollection();

        /** @var \Magento\Wishlist\Model\Item\Interceptor $item */
        foreach ($itemsCollection as $item) {
            $product = clone $item->getProduct();
            $buyRequest = $item->getOptionByCode('info_buyRequest');
            $superAttribute = $item->getBuyRequest()->getSuperAttribute();

            $items[$product->getSku()] = [
                'item' => $item,
                'qty' => $item->getQty(),
                'product' => $product,
                'super_attribute' => $superAttribute,
                'buy_request' => $buyRequest->getValue() ?? ''
            ];

            if ($shouldDeleteItems) {
                $item->delete();
            }
        }

        return $items;
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
        $sharingCode = $args['sharingCode'] ?? null;
        $guestCartId = $args['guestCartId'] ?? null;

        if (!$guestCartId) {
            $customerId = $context->getUserId();
            if ($customerId === null || $customerId === 0) {
                throw new GraphQlAuthorizationException(__('User not found'));
            }

            $cart = $this->quoteManagement->getCartForCustomer($customerId);
        } else {
            $cart = $this->guestCartRepository->get($guestCartId);
        }

        /** @var Wishlist $wishlist */
        $wishlist = $this->wishlistFactory->create();
        $this->loadWishlist($wishlist, $sharingCode, $context);

        if (!$wishlist->getId() || $wishlist->getItemsCount() <= 0) {
            return true;
        }

        $wishlistItems = $this->getWishlistItems($wishlist, !!$sharingCode);
        $cartItems = $cart->getItems();

        foreach ($cartItems as $item) {
            $product = $item->getProduct();
            $qty = $item->getQty();
            $sku = $product->getSku();

            if (array_key_exists($sku, $wishlistItems)) {
                $wishlistItem = $wishlistItems[$sku];
                unset($wishlistItems[$sku]);

                $qty += $wishlistItem['qty'];
                $item->setQty($qty);

                $wishlistItem['item']->delete();
            }
        }

        $this->addItemsToCart($wishlistItems, $cart);

        try {
            $wishlist->save();
            $cart->save();
        } catch (Exception $e) {
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to save wishlist items to cart'));
        }

        return true;
    }

    private function loadWishlist(Wishlist $wishlist, $sharingCode, $context): void
    {
        if (!$sharingCode) {
            $customerId = $context->getUserId();
            $this->wishlistResource->load($wishlist, $customerId, 'customer_id');
            return;
        }

        $this->wishlistResource->load($wishlist, $sharingCode, 'sharing_code');

        if (!$wishlist->getShared()) {
            throw new GraphQlNoSuchEntityException(__('Shared wishlist with provided sharing code does not exist'));
        }
    }
}
