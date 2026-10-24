<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use JoePham\BinancePay\Model\Api\Client;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Psr\Log\LoggerInterface;

/**
 * Brings a pending Magento order in line with its Binance Pay order.
 *
 * The webhook, the return page and cron all call sync(). It asks Binance for the status instead of trusting
 * whoever called, so a replayed or forged notification can at most trigger a status check.
 */
class OrderSync
{
    /** Orders that never reached the Binance checkout are cancelled after this many seconds. */
    private const ABANDON_AFTER = 3600;

    private const STATUS_PAID = 'PAID';
    private const CLOSED_STATUSES = ['CANCELED', 'EXPIRED', 'ERROR'];
    private const REFUND_STATUSES = ['REFUNDING', 'REFUNDED', 'FULL_REFUNDED'];
    private const OPEN_STATUSES = ['INITIAL', 'PENDING'];

    /**
     * @param Client $client
     * @param OrderLocker $locker
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Client $client,
        private readonly OrderLocker $locker,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Query Binance and apply the result: invoice a paid order, cancel a closed one.
     *
     * @param int $orderId
     * @return Order the order as it is after the sync
     * @throws LocalizedException
     */
    public function sync(int $orderId): Order
    {
        return $this->locker->run($orderId, function () use ($orderId): Order {
            $order = $this->load($orderId);
            if (!$this->isAwaitingPayment($order)) {
                return $order;
            }

            $tradeNo = (string)$order->getPayment()->getAdditionalInformation(PaymentInfo::MERCHANT_TRADE_NO);
            if ($tradeNo === '') {
                if (strtotime((string)$order->getCreatedAt() . ' UTC') < time() - self::ABANDON_AFTER) {
                    $this->cancelOrder($order, __('The customer did not reach the Binance Pay checkout.'));
                }
                return $order;
            }

            $this->apply($order, $this->client->queryOrder($tradeNo, (int)$order->getStoreId()));

            return $order;
        });
    }

    /**
     * The shopper left the Binance checkout: close the Binance order, then cancel the Magento order.
     *
     * Binance refuses to close an order the shopper is paying at that moment; the exception is passed on and
     * the order stays pending, so a late payment is never lost.
     *
     * @param int $orderId
     * @return Order
     * @throws LocalizedException
     */
    public function abandon(int $orderId): Order
    {
        return $this->locker->run($orderId, function () use ($orderId): Order {
            $order = $this->load($orderId);
            if (!$this->isAwaitingPayment($order)) {
                return $order;
            }

            $tradeNo = (string)$order->getPayment()->getAdditionalInformation(PaymentInfo::MERCHANT_TRADE_NO);
            if ($tradeNo !== '') {
                $storeId = (int)$order->getStoreId();
                $result = $this->client->queryOrder($tradeNo, $storeId);
                if (in_array($result['status'] ?? '', self::OPEN_STATUSES, true)) {
                    $this->client->closeOrder($tradeNo, $storeId);
                    $result['status'] = 'CANCELED';
                }
                $this->apply($order, $result);
            } else {
                $this->cancelOrder($order, __('The customer cancelled the Binance Pay payment.'));
            }

            return $order;
        });
    }

    /**
     * Cancel an order whose Binance payment could not be started.
     *
     * @param int $orderId
     * @param Phrase $reason
     * @return Order
     * @throws LocalizedException
     */
    public function cancel(int $orderId, Phrase $reason): Order
    {
        return $this->locker->run($orderId, function () use ($orderId, $reason): Order {
            $order = $this->load($orderId);
            if ($this->isAwaitingPayment($order)) {
                $this->cancelOrder($order, $reason);
            }

            return $order;
        });
    }

    /**
     * Record the outcome of a refund that Binance reported through the PAY_REFUND webhook.
     *
     * Only the comment is saved: the webhook can arrive while the credit memo that asked for the refund is
     * still being saved, and saving the whole order here would write back its old totals.
     *
     * @param int $orderId
     * @param string $bizStatus REFUND_SUCCESS or REFUND_REJECTED
     * @param array $refundInfo
     * @return void
     * @throws LocalizedException
     */
    public function addRefundNotice(int $orderId, string $bizStatus, array $refundInfo): void
    {
        $order = $this->load($orderId);
        $args = [
            (string)($refundInfo['refundRequestId'] ?? ''),
            (string)($refundInfo['refundAmount'] ?? ''),
            (string)$order->getPayment()->getAdditionalInformation(PaymentInfo::CURRENCY),
        ];
        $this->historyRepository->save($order->addCommentToStatusHistory(
            $bizStatus === 'REFUND_SUCCESS'
                ? __('Binance Pay completed refund %1 of %2 %3.', ...$args)
                : __('Binance Pay rejected refund %1 of %2 %3. Refund the customer manually.', ...$args)
        ));
    }

    /**
     * Apply a Query Order result to a pending order.
     *
     * @param Order $order
     * @param array $result
     * @return void
     */
    private function apply(Order $order, array $result): void
    {
        $status = (string)($result['status'] ?? '');
        $tradeNo = (string)$order->getPayment()->getAdditionalInformation(PaymentInfo::MERCHANT_TRADE_NO);

        if ($status === self::STATUS_PAID) {
            $this->registerPayment($order, $result);
        } elseif (in_array($status, self::CLOSED_STATUSES, true)) {
            $this->cancelOrder($order, __('Binance Pay order %1 was closed (%2).', $tradeNo, $status));
        } elseif (in_array($status, self::REFUND_STATUSES, true)) {
            $this->holdOrder(
                $order,
                __(
                    'Binance Pay order %1 is %2 although Magento never recorded the payment. '
                    . 'Check it in the Binance Merchant Portal.',
                    $tradeNo,
                    $status
                )
            );
        }
    }

    /**
     * Invoice the order after checking that Binance was paid exactly the amount it was asked for.
     *
     * @param Order $order
     * @param array $result
     * @return void
     */
    private function registerPayment(Order $order, array $result): void
    {
        $payment = $order->getPayment();
        $expectedAmount = (string)$payment->getAdditionalInformation(PaymentInfo::TOTAL_FEE);
        $expectedCurrency = (string)$payment->getAdditionalInformation(PaymentInfo::CURRENCY);
        $paidAmount = (string)($result['orderAmount'] ?? '');
        $paidCurrency = (string)($result['currency'] ?? '');

        if ($paidCurrency !== $expectedCurrency || !$this->isSameAmount($paidAmount, $expectedAmount)) {
            $this->logger->critical('Binance Pay amount mismatch', [
                'order' => $order->getIncrementId(),
                'paid' => $paidAmount . ' ' . $paidCurrency,
                'expected' => $expectedAmount . ' ' . $expectedCurrency,
            ]);
            $this->holdOrder(
                $order,
                __(
                    'Binance Pay reports %1 %2 paid but %3 %4 was expected. Check the payment before shipping.',
                    $paidAmount,
                    $paidCurrency,
                    $expectedAmount,
                    $expectedCurrency
                )
            );
            return;
        }

        $transactionId = (string)($result['transactionId'] ?? '');
        if ($transactionId === '') {
            $transactionId = (string)($result['prepayId']
                ?? $payment->getAdditionalInformation(PaymentInfo::PREPAY_ID));
        }
        $payment->setTransactionId($transactionId);
        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation(PaymentInfo::TRANSACTION_ID, $transactionId);
        $paidWith = $this->describeInstructions((array)($result['paymentInfo']['paymentInstructions'] ?? []));
        if ($paidWith !== '') {
            $payment->setAdditionalInformation(PaymentInfo::PAID_WITH, $paidWith);
        }
        $payment->registerCaptureNotification($order->getBaseGrandTotal(), true);
        $order->addCommentToStatusHistory(
            __('Binance Pay payment received: %1 %2, transaction %3.', $paidAmount, $paidCurrency, $transactionId)
        );
        $this->orderRepository->save($order);

        if (!$order->getEmailSent()) {
            try {
                $this->orderSender->send($order);
            } catch (\Exception $e) {
                $this->logger->error('Could not send the order email', [
                    'order' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Cancel the order and say why.
     *
     * @param Order $order
     * @param Phrase $reason
     * @return void
     */
    private function cancelOrder(Order $order, Phrase $reason): void
    {
        $order->cancel();
        $order->addCommentToStatusHistory($reason);
        $this->orderRepository->save($order);
    }

    /**
     * Put the order on hold for a person to look at.
     *
     * @param Order $order
     * @param Phrase $reason
     * @return void
     */
    private function holdOrder(Order $order, Phrase $reason): void
    {
        if ($order->canHold()) {
            $order->hold();
        }
        $order->addCommentToStatusHistory($reason);
        $this->orderRepository->save($order);
    }

    /**
     * "0.00045 BTC, 1.2 USDT" from Binance payment instructions.
     *
     * @param array $instructions
     * @return string
     */
    private function describeInstructions(array $instructions): string
    {
        $parts = [];
        foreach ($instructions as $instruction) {
            if (is_array($instruction) && isset($instruction['amount'], $instruction['currency'])) {
                $parts[] = $instruction['amount'] . ' ' . $instruction['currency'];
            }
        }

        return implode(', ', $parts);
    }

    /**
     * Compare two decimal strings to 8 places.
     *
     * @param string $first
     * @param string $second
     * @return bool
     */
    private function isSameAmount(string $first, string $second): bool
    {
        return is_numeric($first) && is_numeric($second) && bccomp($first, $second, 8) === 0;
    }

    /**
     * Load the order straight from the database (never a cached instance), inside the lock.
     *
     * @param int $orderId
     * @return Order
     * @throws NoSuchEntityException
     */
    private function load(int $orderId): Order
    {
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);
        if (!$order->getId()) {
            throw new NoSuchEntityException(__('Order %1 does not exist.', $orderId));
        }

        return $order;
    }

    /**
     * Only pending Binance Pay orders are ever changed here.
     *
     * @param Order $order
     * @return bool
     */
    private function isAwaitingPayment(Order $order): bool
    {
        return $order->getState() === Order::STATE_PENDING_PAYMENT
            && $order->getPayment() !== null
            && $order->getPayment()->getMethod() === Config::METHOD_CODE;
    }
}
