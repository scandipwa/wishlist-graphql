<?php
/**
 * ScandiPWA - Progressive Web App for Magento
 *
 * Copyright © Scandiweb, Inc. All rights reserved.
 * See LICENSE for license details.
 *
 * @license OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * @package scandipwa/quote-graphql
 * @link    https://github.com/scandipwa/quote-graphql
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\FileSupport\BuyRequest;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Wishlist\Model\Wishlist\BuyRequest\BuyRequestDataProviderInterface;
use Magento\Wishlist\Model\Wishlist\Data\WishlistItem;

/**
 * Data provider for custom options buy requests
 */
class CustomizableOptionDataProvider implements BuyRequestDataProviderInterface
{
    const PROVIDER_OPTION_TYPE = 'custom-option';
    const QUOTE_MEDIA_PATH = 'custom_options/quote/';
    const ORDER_MEDIA_PATH = 'custom_options/order/';

    /**
     * @var string
     */
    protected $mediaPath;

    /**
     * @param Filesystem $filesystem
     */
    public function __construct(
        Filesystem $filesystem
    ) {
        $this->mediaPath = $filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
    }

    /**
     * @inheritdoc
     *
     * @phpcs:disable Magento2.Functions.DiscouragedFunction
     */
    public function execute(WishlistItem $wishlistItemData, ?int $productId): array
    {
        $customizableOptionsData = [];
        foreach ($wishlistItemData->getSelectedOptions() as $optionData) {
            $optionData = \explode('/', base64_decode($optionData->getId()));

            if ($this->isProviderApplicable($optionData) === false) {
                continue;
            }

            [$optionType, $optionId, $optionValue] = $optionData;

            if ($optionType == self::PROVIDER_OPTION_TYPE) {
                $customizableOptionsData[$optionId][] = $optionValue;
            }
        }

        foreach ($wishlistItemData->getEnteredOptions() as $option) {
            $optionData = \explode('/', base64_decode($option->getUid()));

            if ($this->isProviderApplicable($optionData) === false) {
                continue;
            }

            [$optionType, $optionId] = $optionData;

            // -- File Upload Support --
            $fileData = $this->getFileData($option->getUid(), $option->getValue());
            if ($optionType == self::PROVIDER_OPTION_TYPE) {
                if ($fileData !== false) {
                    $this->createFileAndFolder($option->getUid(), $fileData['raw'], $fileData['title']);
                    unset($fileData['raw']);
                    $customizableOptionsData[$optionId][] = $fileData;
            // -- End of Support --
                } else {
                    $customizableOptionsData[$optionId][] = $option->getValue();
                }
            }
        }

        if (empty($customizableOptionsData)) {
            return $customizableOptionsData;
        }

        $result = ['options' => $this->flattenOptionValues($customizableOptionsData)];

        if ($productId) {
            $result += ['product' => $productId];
        }

        return $result;
    }

    protected function getFileData($uid, $optionData) {
        try {
            $data = json_decode($optionData, true);

            if (!is_array($data)) {
                return false;
            }

            $filename = $data['file_name'];
            $filedata = $data['file_data'];

            $insidePath = $uid . '/_/' . $filename;

            if (!$filename || !$filedata) {
                return false;
            }

            return [
                'type' => 'application/octet-stream',
                'title' => $filename,
                'quote_path' => self::QUOTE_MEDIA_PATH . $insidePath,
                'order_path' => self::ORDER_MEDIA_PATH . $insidePath,
                'fullpath' => $this->mediaPath . self::QUOTE_MEDIA_PATH . $insidePath,
                'secret_key' => $filename,
                'raw' => $filedata
            ];
        } catch(\Exception $e) {
            return false;
        }

        return false;
    }

    /**
     * @param $quoteId
     * @param $value
     * @param $filename
     */
    public function createFileAndFolder($uid, $value, $filename)
    {
        $directory = sprintf(
            '%s%s%s/_',
            $this->mediaPath,
            self::QUOTE_MEDIA_PATH,
            $uid
        );

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents(
            sprintf('%s/%s', $directory, $filename),
            base64_decode(substr($value, strpos($value, ',') + 1 ))
        );
    }

    /**
     * Flatten option values for non-multiselect customizable options
     *
     * @param array $customizableOptionsData
     *
     * @return array
     */
    protected function flattenOptionValues(array $customizableOptionsData): array
    {
        foreach ($customizableOptionsData as $optionId => $optionValue) {
            if (count($optionValue) === 1) {
                $customizableOptionsData[$optionId] = $optionValue[0];
            }
        }

        return $customizableOptionsData;
    }

    /**
     * Checks whether this provider is applicable for the current option
     *
     * @param array $optionData
     * @return bool
     */
    protected function isProviderApplicable(array $optionData): bool
    {
        return $optionData[0] === self::PROVIDER_OPTION_TYPE;
    }
}
