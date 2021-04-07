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

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Downloadable\Model\Product\Type as DownloadableType;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\WishlistFactory;

/**
 * Class SaveProductToWishlist
 * @package ScandiPWA\WishlistGraphQl\Model\Resolver
 */
class SaveProductToWishlist implements ResolverInterface
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
     * SaveProductToWishlist constructor.
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
        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('Authorization unsuccessful'));
        }

        $sku = $args['wishlistItem']['sku'] ?? '';
        $itemId = $args['wishlistItem']['item_id'] ?? '';

        $wishlist = $this->wishlistFactory->create();
        $wishlist->loadByCustomerId($customerId, true);

        if ($sku !== '') {
            return $this->addProductToWishlist($wishlist, $sku, $args['wishlistItem']);
        }

        if ($itemId !== '') {
            return $this->updateWishlistItem($wishlist, $itemId, $args['wishlistItem']);
        }

        throw new GraphQlInputException(__('Please specify either sku or item_id'));
    }

    /**
     * @param string $type
     * @param array $productOption
     * @return array|array[]
     */
    protected function getProductConfigurableData(string $type, array $productOption) : array {
        switch ($type) {
            /** CONFIGURABLE PRODUCTS */
            case ConfigurableType::TYPE_CODE:
                $configurableOptions = $this->getOptionsArray($productOption['extension_attributes']['configurable_item_options']);
                $configurableData['super_attribute'] = $configurableOptions;
                return $configurableData;

            /** GROUP PRODUCTS */
            case GroupedType::TYPE_CODE:
                return [
                    'super_group' => $this->getOptionsArray($productOption['extension_attributes']['grouped_product_options'])
                ];

            /** DOWNLOADABLE PRODUCTS */
            case DownloadableType::TYPE_DOWNLOADABLE:
                $configurableData = [
                    'links' => []
                ];
                $downloadableLinks = $productOption['extension_attributes']['downloadable_product_links'] ?? [];
                foreach ($downloadableLinks as $link) {
                    $linkId = $link['link_id'];
                    $configurableData['links'][$linkId] = $linkId;
                }
                return $configurableData;

            /** BUNDLE PRODUCTS */
            case BundleType::TYPE_CODE:
                $configurableData = [];
                $bundleOptions = $productOption['extension_attributes']['bundle_options'] ?? [];
                foreach ($bundleOptions as $bundleOption) {
                    $optionId = $bundleOption['id'];
                    $configurableData['bundle_option'][$optionId][] = $bundleOption['value'];
                    $configurableData['bundle_option_qty'][$optionId] = $bundleOption['quantity'];
                }
                return $configurableData;

            default:
                return [];
        }
    }

    protected function addProductToWishlist(Wishlist $wishlist, string $sku, array $parameters)
    {
        $quantity = $parameters['quantity'] ?? 1;
        $description = $parameters['description'] ?? '';
        $productOption = $parameters['product_option'] ?? [];

        $product = $this->productRepository->get($sku);
        if (!$product->isVisibleInCatalog()) {
            throw new GraphQlInputException(__('Please specify valid product'));
        }

        try {
            $configurableData = $this->getProductConfigurableData($product->getTypeId(), $productOption);
            $wishlistItem = $wishlist->addNewItem($product, $configurableData);
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
            ['product' => array_merge(
                $wishlistItem->getProduct()->getData(),
                ['model' => $product]
            )]
        );
    }

    protected function updateWishlistItem(Wishlist $wishlist, string $itemId, array $parameters)
    {
        if (!(array_key_exists('quantity', $parameters) || array_key_exists('description', $parameters))) {
            throw new GraphQlInputException(__('Please specify either quantity or description to update'));
        }

        $item = $wishlist->getItem($itemId);

        if (!$item->getId()) {
            throw new GraphQlInputException(__('Please specify a valid wishlist item'));
        }

        if ($wishlist->getId() !== $item->getWishlistId()) {
            throw new GraphQlNoSuchEntityException(__('Invalid wishlist'));
        }

        if (array_key_exists('quantity', $parameters)) {
            $item->setQty($parameters['quantity']);
        }

        if (array_key_exists('description', $parameters)) {
            $item->setDescription($parameters['description']);
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

    protected function getOptionsArray(array $configurableOptions)
    {
        $optionsArray = [];
        foreach ($configurableOptions as ['option_id' => $id, 'option_value' => $value]) {
            $optionsArray[(string) $id] = (int) $value;
        }

        return $optionsArray;
    }
}
