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
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\DataObject;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
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
use Magento\CatalogInventory\Model\Stock\StockItemRepository;

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
     * @var StockItemRepository
     */
    protected $stockItemRepository;

    public function __construct(
        ParamOverriderCartId $overriderCartId,
        CartRepositoryInterface $quoteRepository,
        CartManagementInterface $quoteManagement,
        GuestCartRepositoryInterface $guestCartRepository,
        WishlistFactory $wishlistFactory,
        WishlistResourceModel $wishlistResource,
        StockItemRepository $stockItemRepository
    ) {
        $this->overriderCartId = $overriderCartId;
        $this->quoteRepository = $quoteRepository;
        $this->quoteManagement = $quoteManagement;
        $this->wishlistFactory = $wishlistFactory;
        $this->wishlistResource = $wishlistResource;
        $this->guestCartRepository = $guestCartRepository;
        $this->stockItemRepository = $stockItemRepository;
    }

    /**
     * Adds new items from wishlist to cart
     *
     * @param array $wishlistItems
     * @return void
     */
    protected function addItemsToCart(array $wishlistItems, CartInterface $quote): void
    {
        $cartItems = $quote->getItems();
        $qtyGotChanged = false;
        $shouldShowError = false;

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
                $qtyGotChanged = true;
            }
        }

        $atLeastOneProductAdded = false;

        foreach ($wishlistItems as $item) {
            $product = $item['product'];
            $data = [];

            if ($product->getTypeId() === Configurable::TYPE_CODE) {
                $data['super_attribute'] = $item['super_attribute'];
            }

            $buyRequest = new DataObject();
            $buyRequest->setData($data);
            $stock = $this->stockItemRepository->get($product->getId());

            if ($stock->getIsInStock()) {
                $quoteItem = $quote->addProduct($product, $buyRequest);
                $quoteItem->setQty($item['qty']);
                $item['item']->delete();
                $atLeastOneProductAdded = true;
            }
        }

        if (!$atLeastOneProductAdded && !$qtyGotChanged) $shouldShowError = true;

        try {
            if ($shouldShowError){
                throw new GraphQlInputException(__('Could not save any wishlist items to cart'));
            }

            $this->quoteRepository->save($quote);
        } catch (Exception $e) {
            throw new GraphQlNoSuchEntityException(__('There was an error when trying to save wishlist items to cart'));
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
            $product = $item->getProduct();
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
