<?php
/**
 * Thinkbeat_SmartOrderGrid Collection Plugin
 *
 * @category  Thinkbeat
 * @package   Thinkbeat_SmartOrderGrid
 * @author    Thinkbeat
 * @copyright Copyright (c) 2026 Thinkbeat
 */

declare(strict_types=1);

namespace Thinkbeat\SmartOrderGrid\Plugin\Sales\Model\ResourceModel\Order\Grid;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;
use Magento\Framework\App\ResourceConnection;

class Collection
{
    /**
     * @var bool
     */
    private $orderItemsJoined = false;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ResourceConnection $resourceConnection
    ) {
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Before load - ensure order items are joined
     *
     * @param OrderGridCollection $subject
     * @return void
     */
    public function beforeLoad(OrderGridCollection $subject)
    {
        if (!$subject->isLoaded()) {
            $this->joinOrderItems($subject);
        }
    }

    /**
     * Around addFieldToFilter - handle custom filters
     *
     * @param OrderGridCollection $subject
     * @param \Closure $proceed
     * @param string|array $field
     * @param mixed $condition
     * @return OrderGridCollection
     */
    public function aroundAddFieldToFilter(
        OrderGridCollection $subject,
        \Closure $proceed,
        $field,
        $condition = null
    ) {
        // Handle custom filter fields
        if ($field === 'order_items_name' || $field === 'order_items_sku' || $field === 'order_items_count') {
            $this->joinOrderItems($subject);
            
            // Get the actual field name from the joined table
            $mappedField = 'order_items.' . $field;
            
            // Apply the filter using the parent method with mapped field
            return $proceed($mappedField, $condition);
        }

        return $proceed($field, $condition);
    }

    /**
     * Join order items data using subquery
     * 
     * @param OrderGridCollection $collection
     * @return void
     */
    private function joinOrderItems(OrderGridCollection $collection)
    {
        if ($this->orderItemsJoined) {
            return;
        }

        $select = $collection->getSelect();
        $fromPart = $select->getPart(\Magento\Framework\DB\Select::FROM);
        
        if (!isset($fromPart['order_items'])) {
            $connection = $collection->getConnection();
            $orderItemTable = $this->resourceConnection->getTableName('sales_order_item');
            
            // Create a subquery with GROUP BY to aggregate order items
            $subSelect = $connection->select()
                ->from(
                    ['items' => $orderItemTable],
                    [
                        'order_id',
                        'order_items_data' => new \Zend_Db_Expr(
                            "GROUP_CONCAT(
                                CONCAT_WS('|',
                                    items.name,
                                    items.sku,
                                    CAST(items.qty_ordered AS CHAR),
                                    COALESCE(items.product_id, '0')
                                )
                                SEPARATOR '||'
                            )"
                        ),
                        'order_items_count' => new \Zend_Db_Expr('COUNT(items.item_id)'),
                        'order_items_sku' => new \Zend_Db_Expr(
                            "GROUP_CONCAT(DISTINCT items.sku SEPARATOR ', ')"
                        ),
                        'order_items_name' => new \Zend_Db_Expr(
                            "GROUP_CONCAT(DISTINCT items.name SEPARATOR ', ')"
                        )
                    ]
                )
                ->where('items.parent_item_id IS NULL')
                ->group('items.order_id');
            
            // Join the subquery result
            $collection->getSelect()->joinLeft(
                ['order_items' => $subSelect],
                'main_table.entity_id = order_items.order_id',
                [
                    'order_items_name' => 'order_items.order_items_name',
                    'order_items_sku' => 'order_items.order_items_sku',
                    'order_items_count' => 'order_items.order_items_count',
                    'order_items_data' => 'order_items.order_items_data'
                ]
            );
            
            $this->orderItemsJoined = true;
        }
    }
}
