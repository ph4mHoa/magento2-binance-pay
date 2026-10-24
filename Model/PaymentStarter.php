<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use JoePham\BinancePay\Model\Api\ApiException;
use JoePham\BinancePay\Model\Api\Client;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;

/**
 * Creates the Binance Pay order for a placed Magento order and returns the hosted checkout URL.
 */
class PaymentStarter
{
    /** Do not send the shopper to a checkout page that expires within this many seconds. */
    private const MIN_REMAINING_SECONDS = 60;

    /**
     * @param Client $client
     * @param OrderRequestBuilder $requestBuilder
     * @param OrderLocker $locker
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        private readonly Client $client,
        private readonly OrderRequestBuilder $requestBuilder,
        private readonly OrderLocker $locker,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderRepositoryInterface $orderRepository
    ) {
    }

    /**
     * Binance checkout URL for the order; the Binance order is created on the first call only.
     *
     * @param int $orderId
     * @return string
     * @throws LocalizedException
     */
    public function start(int $orderId): string
    {
        return $this->locker->run($orderId, function () use ($orderId): string {
            $order = $this->orderFactory->create();
            $this->orderResource->load($order, $orderId);
            if (!$order->getId() || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
                throw new LocalizedException(__('This order is not waiting for a Binance Pay payment.'));
            }

            $payment = $order->getPayment();
            $checkoutUrl = (string)$payment->getAdditionalInformation(PaymentInfo::CHECKOUT_URL);
            if ($checkoutUrl !== '') {
                $expireTime = (int)$payment->getAdditionalInformation(PaymentInfo::EXPIRE_TIME);
                if ($expireTime > (time() + self::MIN_REMAINING_SECONDS) * 1000) {
                    return $checkoutUrl;
                }
                throw new LocalizedException(__('The Binance Pay payment for this order has expired.'));
            }

            $tradeNo = $this->requestBuilder->createMerchantTradeNo($order);
            $result = $this->client->createOrder(
                $this->requestBuilder->build($order, $tradeNo),
                (int)$order->getStoreId()
            );
            if (empty($result['checkoutUrl']) || empty($result['prepayId'])) {
                throw new ApiException(__('Binance Pay did not return a checkout URL.'));
            }

            $totalFee = (string)($result['totalFee'] ?? '');
            $currency = (string)($result['currency'] ?? '');
            $payment->setAdditionalInformation(PaymentInfo::MERCHANT_TRADE_NO, $tradeNo);
            $payment->setAdditionalInformation(PaymentInfo::PREPAY_ID, (string)$result['prepayId']);
            $payment->setAdditionalInformation(PaymentInfo::CHECKOUT_URL, (string)$result['checkoutUrl']);
            $payment->setAdditionalInformation(PaymentInfo::EXPIRE_TIME, (int)($result['expireTime'] ?? 0));
            $payment->setAdditionalInformation(PaymentInfo::TOTAL_FEE, $totalFee);
            $payment->setAdditionalInformation(PaymentInfo::CURRENCY, $currency);
            $payment->setAdditionalInformation(PaymentInfo::ORDER_AMOUNT, trim($totalFee . ' ' . $currency));
            $order->addCommentToStatusHistory(
                __(
                    'Binance Pay order %1 created (%2) for %3 %4.',
                    $tradeNo,
                    (string)$result['prepayId'],
                    $totalFee,
                    $currency
                )
            );
            $this->orderRepository->save($order);

            return (string)$result['checkoutUrl'];
        });
    }
}
