<?php
/**
 * Copyright © Thinkbeat. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Thinkbeat\SmartOrderGrid\Ui\Component\Listing\Column;

use Magento\Framework\Escaper;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Order Items column renderer for sales order grid
 *
 * Displays order items with thumbnails, expand/collapse, and hover tooltip
 */
class OrderItems extends Column
{
    /**
     * Maximum number of items to display before truncating
     */
    private const MAX_VISIBLE_ITEMS = 3;

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var ImageHelper
     */
    private $imageHelper;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param Escaper $escaper
     * @param ImageHelper $imageHelper
     * @param ProductRepositoryInterface $productRepository
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        Escaper $escaper,
        ImageHelper $imageHelper,
        ProductRepositoryInterface $productRepository,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
        $this->escaper = $escaper;
        $this->imageHelper = $imageHelper;
        $this->productRepository = $productRepository;
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            if (isset($item['order_items_data'])) {
                $item[$this->getData('name')] = $this->formatOrderItems(
                    $item['order_items_data'],
                    $item['entity_id'] ?? ''
                );
            } else {
                $item[$this->getData('name')] = '<span style="color: #999;">—</span>';
            }
        }

        return $dataSource;
    }

    /**
     * Format order items data into HTML output
     *
     * @param string|null $orderItemsData
     * @param string $orderId
     * @return string
     */
    private function formatOrderItems(?string $orderItemsData, string $orderId): string
    {
        if (empty($orderItemsData)) {
            return '<span style="color: #999;">No items</span>';
        }

        $items = explode('||', $orderItemsData);
        $totalItems = count($items);

        if ($totalItems === 0) {
            return '<span style="color: #999;">No items</span>';
        }

        $uniqueId = 'order-items-' . $orderId;
        $modalId = 'modal-' . $uniqueId;
        $html = '<div class="order-items-column" style="line-height: 1.6;">';

        $visibleItems = array_slice($items, 0, self::MAX_VISIBLE_ITEMS);
        $hiddenItems = array_slice($items, self::MAX_VISIBLE_ITEMS);

        foreach ($visibleItems as $itemData) {
            $html .= $this->formatSingleItem($itemData);
        }

        if (!empty($hiddenItems)) {
            $moreCount = count($hiddenItems);

            // Add click handler to show modal - prevent default and stop propagation
            $html .= sprintf(
                '<div style="color: #1979c3; font-size: 0.9em; cursor: pointer; margin-top: 2px;" '
                . 'onclick="event.preventDefault(); event.stopPropagation(); '
                . 'document.getElementById(\'%s\').style.display=\'flex\'; return false;">▶ +%d more (click to view)</div>',
                $modalId,
                $moreCount
            );

            // Create modal popup
            $html .= $this->buildModal($modalId, $orderId, $items);
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Build modal popup for all order items
     *
     * @param string $modalId
     * @param string $orderId
     * @param array $allItems
     * @return string
     */
    private function buildModal(string $modalId, string $orderId, array $allItems): string
    {
        $modalHtml = sprintf(
            '<div id="%s" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; '
            . 'width: 100%%; height: 100%%; overflow: auto; background-color: rgba(0,0,0,0.6); '
            . 'align-items: center; justify-content: center;">',
            $modalId
        );

        $modalHtml .= '<div style="background-color: #fff; padding: 0; '
            . 'border-radius: 8px; width: 90%%; max-width: 800px; '
            . 'max-height: 90vh; box-shadow: 0 5px 30px rgba(0,0,0,0.4); '
            . 'display: flex; flex-direction: column;" onclick="event.stopPropagation();">';

        // Modal Header
        $modalHtml .= sprintf(
            '<div style="padding: 20px 25px; border-bottom: 1px solid #e0e0e0; display: flex; '
            . 'justify-content: space-between; align-items: center; background: linear-gradient(to bottom, #f8f9fa, #fff); '
            . 'border-radius: 8px 8px 0 0; flex-shrink: 0;"><h3 style="margin: 0; color: #333; font-size: 18px;">'
            . 'Order #%s - All Items (%d)</h3>'
            . '<span onclick="document.getElementById(\'%s\').style.display=\'none\';" '
            . 'style="font-size: 32px; font-weight: 300; color: #999; cursor: pointer; '
            . 'line-height: 1; width: 32px; height: 32px; display: flex; align-items: center; '
            . 'justify-content: center; border-radius: 50%%; transition: all 0.2s;" '
            . 'onmouseover="this.style.background=\'#f0f0f0\'; this.style.color=\'#333\';" '
            . 'onmouseout="this.style.background=\'transparent\'; this.style.color=\'#999\';">&times;</span></div>',
            $orderId,
            count($allItems),
            $modalId
        );

        // Modal Body
        $modalHtml .= '<div style="padding: 20px 25px; overflow-y: auto; flex: 1;">';

        foreach ($allItems as $index => $itemData) {
            $modalHtml .= '<div style="padding: 12px 0; border-bottom: 1px solid #f5f5f5; display: flex; align-items: center;">';
            $modalHtml .= sprintf(
                '<span style="color: #999; margin-right: 15px; font-weight: 600; min-width: 30px;">%d.</span>',
                $index + 1
            );
            $modalHtml .= $this->formatSingleItem($itemData);
            $modalHtml .= '</div>';
        }

        $modalHtml .= '</div>';

        // Modal Footer
        $modalHtml .= sprintf(
            '<div style="padding: 15px 25px; border-top: 1px solid #e0e0e0; text-align: right; '
            . 'background: #f8f9fa; border-radius: 0 0 8px 8px; flex-shrink: 0;">'
            . '<button onclick="document.getElementById(\'%s\').style.display=\'none\';" '
            . 'style="background: #1979c3; color: white; border: none; padding: 10px 24px; '
            . 'border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; '
            . 'transition: background 0.2s;" '
            . 'onmouseover="this.style.background=\'#1565a3\';" '
            . 'onmouseout="this.style.background=\'#1979c3\';">Close</button></div>',
            $modalId
        );

        $modalHtml .= '</div></div>';

        // Close modal when clicking outside (on backdrop)
        $modalHtml .= sprintf(
            '<script>document.getElementById("%s").onclick = function(e) { '
            . 'if (e.target.id === "%s") this.style.display = "none"; };</script>',
            $modalId,
            $modalId
        );

        return $modalHtml;
    }

    /**
     * Format a single order item
     *
     * @param string $itemData
     * @return string
     */
    private function formatSingleItem(string $itemData): string
    {
        $parts = explode('|', $itemData);

        if (count($parts) < 4) {
            return '';
        }

        [$name, $sku, $qty, $productId] = $parts;

        $displayName = mb_strlen($name) > 40
            ? mb_substr($name, 0, 37) . '...'
            : $name;

        $escapedName = $this->escaper->escapeHtml($displayName);
        $escapedSku = $this->escaper->escapeHtml($sku);
        $qtyFormatted = (float)$qty == (int)$qty ? (int)$qty : $qty;

        $thumbnail = $this->getProductThumbnail((int)$productId);

        $html = '<div style="margin-bottom: 5px; display: flex; align-items: center; gap: 8px;">';

        if ($thumbnail) {
            $html .= sprintf(
                '<img src="%s" alt="%s" style="width: 32px; height: 32px; '
                . 'object-fit: cover; border-radius: 3px; border: 1px solid #ddd;" '
                . 'title="%s"/>',
                $thumbnail,
                $escapedName,
                $escapedName
            );
        }

        $html .= sprintf(
            '<div><strong>%s</strong> × %s '
            . '<span style="color: #666; font-size: 0.85em;">(%s)</span></div>',
            $escapedName,
            $qtyFormatted,
            $escapedSku
        );

        $html .= '</div>';
        return $html;
    }

    /**
     * Get product thumbnail URL
     *
     * @param int $productId
     * @return string|null
     */
    private function getProductThumbnail(int $productId): ?string
    {
        if ($productId <= 0) {
            return null;
        }

        try {
            $product = $this->productRepository->getById($productId);
            $imageUrl = $this->imageHelper->init($product, 'product_thumbnail_image')->getUrl();
            return $imageUrl;
        } catch (NoSuchEntityException $e) {
            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build tooltip content for hidden items
     *
     * @param array $hiddenItems
     * @return string
     */
    private function buildTooltipContent(array $hiddenItems): string
    {
        $lines = [];

        foreach ($hiddenItems as $itemData) {
            $parts = explode('|', $itemData);

            if (count($parts) < 3) {
                continue;
            }

            [$name, $sku, $qty] = $parts;
            $qtyFormatted = (float)$qty == (int)$qty ? (int)$qty : $qty;
            $lines[] = sprintf('%s × %s (%s)', $name, $qtyFormatted, $sku);
        }

        return implode("\n", $lines);
    }
}
