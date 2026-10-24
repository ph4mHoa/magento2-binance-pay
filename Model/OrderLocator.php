<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;

/**
 * Finds the Magento order behind a Binance merchantTradeNo.
 *
 * Payment webhooks carry the order ID in passThroughInfo. Refund webhooks do not, so the order is also found
 * by increment ID: the trade number is "<prefix><increment ID>", and every suffix of it is tried in one
 * indexed query. Either way the order only counts when its stored trade number matches exactly.
 */
class OrderLocator
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * The Binance Pay order with this trade number, or null.
     *
     * @param string $merchantTradeNo
     * @param int|null $orderIdHint order ID from passThroughInfo
     * @return Order|null
     */
    public function locate(string $merchantTradeNo, ?int $orderIdHint = null): ?Order
    {
        if ($merchantTradeNo === '') {
            return null;
        }

        $collection = $this->collectionFactory->create();
        if ($orderIdHint) {
            $collection->addFieldToFilter('entity_id', $orderIdHint);
        } else {
            $suffixes = [];
            for ($i = 0, $length = strlen($merchantTradeNo); $i < $length; $i++) {
                $suffixes[] = substr($merchantTradeNo, $i);
            }
            $collection->addFieldToFilter('increment_id', ['in' => $suffixes]);
        }

        /** @var Order $order */
        foreach ($collection as $order) {
            $payment = $order->getPayment();
            if ($payment !== null
                && $payment->getMethod() === Config::METHOD_CODE
                && $payment->getAdditionalInformation(PaymentInfo::MERCHANT_TRADE_NO) === $merchantTradeNo
            ) {
                return $order;
            }
        }

        return $orderIdHint ? $this->locate($merchantTradeNo) : null;
    }
}
