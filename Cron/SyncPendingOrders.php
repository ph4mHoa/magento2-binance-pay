<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Cron;

use JoePham\BinancePay\Model\Config;
use JoePham\BinancePay\Model\OrderSync;
use Magento\Framework\DB\Select;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Safety net for missed webhooks: re-checks recent Binance Pay orders that are still pending payment.
 */
class SyncPendingOrders
{
    /** Leave fresh orders to the webhook and the return page. */
    private const MIN_AGE = 300;
    /** Binance orders expire after an hour; two days covers any late webhook retry. */
    private const MAX_AGE = 172800;
    private const BATCH_SIZE = 200;

    /**
     * @param CollectionFactory $collectionFactory
     * @param OrderSync $orderSync
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly OrderSync $orderSync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sync each pending order; one failure does not stop the rest.
     *
     * @return void
     */
    public function execute(): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('main_table.state', Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('main_table.created_at', ['lt' => gmdate('Y-m-d H:i:s', time() - self::MIN_AGE)])
            ->addFieldToFilter('main_table.created_at', ['gt' => gmdate('Y-m-d H:i:s', time() - self::MAX_AGE)]);
        $select = $collection->getSelect()
            ->reset(Select::COLUMNS)
            ->columns(['main_table.entity_id'])
            ->join(
                ['payment' => $collection->getTable('sales_order_payment')],
                'payment.parent_id = main_table.entity_id',
                []
            )
            ->where('payment.method = ?', Config::METHOD_CODE)
            ->limit(self::BATCH_SIZE);

        foreach ($collection->getConnection()->fetchCol($select) as $orderId) {
            try {
                $this->orderSync->sync((int)$orderId);
            } catch (\Exception $e) {
                $this->logger->error('Binance Pay cron sync failed', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
