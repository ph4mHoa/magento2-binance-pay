<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Gateway\Command;

use JoePham\BinancePay\Model\Api\Client;
use JoePham\BinancePay\Model\OrderRequestBuilder;
use JoePham\BinancePay\Model\PaymentInfo;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;

/**
 * Online refund through the Binance Pay Refund Order API.
 *
 * Binance refunds in the crypto the order was settled in, so the credit memo amount (base currency) is turned
 * into the same share of the crypto total. The last refund that brings the order to fully refunded sends
 * the exact remainder, so rounding never leaves dust behind.
 */
class RefundCommand implements CommandInterface
{
    private const SCALE = 8;
    private const REQUEST_ID_LIMIT = 64;

    /**
     * @param Client $client
     * @param OrderRequestBuilder $requestBuilder
     */
    public function __construct(
        private readonly Client $client,
        private readonly OrderRequestBuilder $requestBuilder
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(array $commandSubject)
    {
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();
        $baseAmount = $this->decimal(SubjectReader::readAmount($commandSubject));
        $order = $payment->getOrder();

        $prepayId = (string)$payment->getAdditionalInformation(PaymentInfo::PREPAY_ID);
        $tradeNo = (string)$payment->getAdditionalInformation(PaymentInfo::MERCHANT_TRADE_NO);
        $totalFee = (string)$payment->getAdditionalInformation(PaymentInfo::TOTAL_FEE);
        $currency = (string)$payment->getAdditionalInformation(PaymentInfo::CURRENCY);
        if ($prepayId === '' || !is_numeric($totalFee)) {
            throw new LocalizedException(__('This order has no Binance Pay payment to refund online.'));
        }

        $refunded = (string)($payment->getAdditionalInformation(PaymentInfo::REFUNDED_AMOUNT) ?: '0');
        $remaining = bcsub($totalFee, $refunded, self::SCALE);
        $refundAmount = $this->cryptoAmount(
            $baseAmount,
            $this->decimal($order->getBaseGrandTotal()),
            $this->decimal($order->getBaseTotalRefunded()),
            $totalFee,
            $remaining
        );
        if (bccomp($refundAmount, '0', self::SCALE) <= 0) {
            throw new LocalizedException(__('Nothing is left to refund through Binance Pay.'));
        }

        // A retry after a failed call reuses the same ID, so Binance can recognise a duplicate request.
        $refundCount = (int)$payment->getAdditionalInformation(PaymentInfo::REFUND_COUNT);
        $requestId = substr($tradeNo . 'R' . ($refundCount + 1), 0, self::REQUEST_ID_LIMIT);
        $storeId = (int)$order->getStoreId();

        $result = $this->client->refund(
            [
                'refundRequestId' => $requestId,
                'prepayId' => $prepayId,
                'refundAmount' => (float)$refundAmount,
                'refundReason' => (string)__('Refund for order %1', $order->getIncrementId()),
                'webhookUrl' => $this->requestBuilder->getWebhookUrl($storeId),
            ],
            $storeId
        );

        $payment->setAdditionalInformation(PaymentInfo::REFUND_COUNT, $refundCount + 1);
        $payment->setAdditionalInformation(
            PaymentInfo::REFUNDED_AMOUNT,
            bcadd($refunded, $refundAmount, self::SCALE)
        );
        $payment->setTransactionId((string)($result['refundId'] ?? $requestId));
        $payment->setIsTransactionClosed(true);
        $invoice = $payment->getCreditmemo() ? $payment->getCreditmemo()->getInvoice() : null;
        if ($invoice) {
            $payment->setShouldCloseParentTransaction(!$invoice->canRefund());
        }
        $order->addCommentToStatusHistory(
            __(
                'Binance Pay refund %1 requested: %2 %3 (status %4).',
                $requestId,
                $refundAmount,
                $currency,
                (string)($result['refundStatus'] ?? '')
            )
        );

        return null;
    }

    /**
     * Crypto amount for a base-currency refund.
     *
     * @param string $baseAmount this refund, base currency
     * @param string $baseGrandTotal order total, base currency
     * @param string $baseTotalRefunded refunded so far including this credit memo, base currency
     * @param string $totalFee order total in crypto
     * @param string $remaining crypto not yet refunded
     * @return string
     */
    public function cryptoAmount(
        string $baseAmount,
        string $baseGrandTotal,
        string $baseTotalRefunded,
        string $totalFee,
        string $remaining
    ): string {
        if (!is_numeric($baseGrandTotal) || bccomp($baseGrandTotal, '0', 4) <= 0) {
            return '0';
        }
        if (is_numeric($baseTotalRefunded) && bccomp($baseTotalRefunded, $baseGrandTotal, 4) >= 0) {
            return $remaining;
        }
        $amount = bcdiv(bcmul($baseAmount, $totalFee, 16), $baseGrandTotal, self::SCALE);

        return bccomp($amount, $remaining, self::SCALE) > 0 ? $remaining : $amount;
    }

    /**
     * Money value as a plain decimal string that bcmath accepts.
     *
     * @param mixed $value
     * @return string
     */
    private function decimal($value): string
    {
        return number_format((float)$value, 4, '.', '');
    }
}
