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

namespace ScandiPWA\WishlistGraphQl\Model\FileSupport\BuyRequest;

use Magento\Framework\Exception\LocalizedException;
use Magento\Wishlist\Model\Wishlist\BuyRequest\BuyRequestDataProviderInterface;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;

/**
 * Data provider for bundle product buy requests
 */
class BundleDataProvider implements BuyRequestDataProviderInterface
{
    const PROVIDER_OPTION_TYPE = 'bundle';
    const BUNDLE_OPTION_DATA_COUNT = 4; // number of bundleOption decoding elements

    /**
     * @inheritdoc
     *
     * @phpcs:disable Magento2.Functions.DiscouragedFunction
     */
    public function execute(WishlistItem $wishlistItem, ?int $productId): array
    {
        $bundleOptionsData = [];

        $this->getBundleOptionsData($wishlistItem->getSelectedOptions(), $bundleOptionsData, "getId");
        //for bundle options with custom quantity
        $this->getBundleOptionsData($wishlistItem->getEnteredOptions(), $bundleOptionsData, "getUid");

        return $bundleOptionsData;
    }

    protected function getBundleOptionsData($wishlistOptions, &$bundleOptionsData, $decodeBy)
    {
        foreach ($wishlistOptions as $option) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $optionData = \explode('/', base64_decode($option->$decodeBy()));
            if ($this->isProviderApplicable($optionData) === false) {
                continue;
            }
            if ($decodeBy === 'getId') {
                [$optionType, $optionId, $optionValueId, $optionQuantity] = $optionData;

            } elseif ($decodeBy === 'getUid') {
                    $this->validateInput($optionData);
                    [$optionType, $optionId, $optionValueId] = $optionData;
                    $optionQuantity = $option->getValue();
            }
            if ($optionType == self::PROVIDER_OPTION_TYPE) {
                $bundleOptionsData['bundle_option'][$optionId][] = $optionValueId;
                $bundleOptionsData['bundle_option_qty'][$optionId][] = $optionQuantity;
            }
        }
    }

    /**
     * Validates the provided options structure
     *
     * @param array $optionData
     * @throws LocalizedException
     */
    protected function validateInput(array $optionData): void
    {
        if (count($optionData) !== self::BUNDLE_OPTION_DATA_COUNT) {
            $errorMessage = __('Wrong format of the entered option data');
            throw new LocalizedException($errorMessage);
        }
    }

    /**
     * Checks whether this provider is applicable for the current option
     *
     * @param array $optionData
     *
     * @return bool
     */
    protected function isProviderApplicable(array $optionData): bool
    {
        return $optionData[0] === self::PROVIDER_OPTION_TYPE;
    }
}
