<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Gateway\Command;

use JoePham\BinancePay\Gateway\Command\RefundCommand;
use JoePham\BinancePay\Model\Api\Client;
use JoePham\BinancePay\Model\OrderRequestBuilder;
use JoePham\BinancePay\Model\PaymentInfo;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RefundCommandTest extends TestCase
{
    /**
     * @var Client|MockObject
     */
    private $client;

    /**
     * @var RefundCommand
     */
    private RefundCommand $command;

    /**
     * @var array payment additional information
     */
    private array $info = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $requestBuilder = $this->createMock(OrderRequestBuilder::class);
        $requestBuilder->method('getWebhookUrl')->willReturnCallback(
            fn(int $storeId) => 'https://shop.test/joepham_binancepay/webhook/?store=' . $storeId
        );
        $this->command = new RefundCommand($this->client, $requestBuilder);
    }

    public function testPartialRefundIsTheSameShareOfTheCryptoTotal(): void
    {
        $this->assertSame('5.00000000', $this->command->cryptoAmount('50.0000', '100.0000', '50.0000', '10', '10'));
        $this->assertSame('3.33333333', $this->command->cryptoAmount('33.3300', '99.9900', '33.3300', '10', '10'));
    }

    public function testLastRefundSendsTheExactRemainder(): void
    {
        $this->assertSame(
            '6.66666667',
            $this->command->cryptoAmount('66.6600', '99.9900', '99.9900', '10', '6.66666667')
        );
    }

    public function testRefundIsNeverMoreThanWhatIsLeft(): void
    {
        $this->assertSame('1.00000000', $this->command->cryptoAmount('50.0000', '100.0000', '60.0000', '10', '1.00000000'));
        $this->assertSame('0', $this->command->cryptoAmount('5.0000', '0.0000', '5.0000', '10', '10'));
    }

    public function testRefundCallsBinanceAndRecordsTheRefund(): void
    {
        $this->info = [
            PaymentInfo::PREPAY_ID => '2900000000000001',
            PaymentInfo::MERCHANT_TRADE_NO => 'STG000000123',
            PaymentInfo::TOTAL_FEE => '25.00000000',
            PaymentInfo::CURRENCY => 'USDT',
        ];
        [$payment, $order] = $this->payment(100.0, 40.0, true);

        $this->client->expects($this->once())->method('refund')
            ->with(
                [
                    'refundRequestId' => 'STG000000123R1',
                    'prepayId' => '2900000000000001',
                    'refundAmount' => 10.0,
                    'refundReason' => 'Refund for order 000000123',
                    'webhookUrl' => 'https://shop.test/joepham_binancepay/webhook/?store=1',
                ],
                1
            )
            ->willReturn(['refundId' => 'RF1', 'refundStatus' => 'REFUNDING']);
        $payment->expects($this->once())->method('setTransactionId')->with('RF1');
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true);
        $payment->expects($this->once())->method('setShouldCloseParentTransaction')->with(false);
        $order->expects($this->once())->method('addCommentToStatusHistory');

        $this->command->execute(['payment' => $this->paymentDataObject($payment), 'amount' => 40.0]);

        $this->assertSame(1, $this->info[PaymentInfo::REFUND_COUNT]);
        $this->assertSame('10.00000000', $this->info[PaymentInfo::REFUNDED_AMOUNT]);
    }

    public function testSecondRefundUsesTheNextRequestIdAndTheRemainder(): void
    {
        $this->info = [
            PaymentInfo::PREPAY_ID => '2900000000000001',
            PaymentInfo::MERCHANT_TRADE_NO => 'STG000000123',
            PaymentInfo::TOTAL_FEE => '25.00000000',
            PaymentInfo::CURRENCY => 'USDT',
            PaymentInfo::REFUND_COUNT => 1,
            PaymentInfo::REFUNDED_AMOUNT => '10.00000000',
        ];
        [$payment] = $this->payment(100.0, 100.0, false);

        $this->client->expects($this->once())->method('refund')
            ->with($this->callback(fn(array $request) => $request['refundRequestId'] === 'STG000000123R2'
                && $request['refundAmount'] === 15.0))
            ->willReturn(['refundId' => 'RF2']);
        $payment->expects($this->once())->method('setShouldCloseParentTransaction')->with(true);

        $this->command->execute(['payment' => $this->paymentDataObject($payment), 'amount' => 60.0]);

        $this->assertSame('25.00000000', $this->info[PaymentInfo::REFUNDED_AMOUNT]);
    }

    public function testOrderWithoutBinancePaymentCannotBeRefundedOnline(): void
    {
        [$payment] = $this->payment(100.0, 100.0, false);
        $this->client->expects($this->never())->method('refund');

        $this->expectException(LocalizedException::class);
        $this->command->execute(['payment' => $this->paymentDataObject($payment), 'amount' => 100.0]);
    }

    public function testFailedBinanceCallLeavesTheRefundUnrecorded(): void
    {
        $this->info = [
            PaymentInfo::PREPAY_ID => 'P1',
            PaymentInfo::MERCHANT_TRADE_NO => 'T1',
            PaymentInfo::TOTAL_FEE => '10',
            PaymentInfo::CURRENCY => 'USDT',
        ];
        [$payment] = $this->payment(10.0, 10.0, false);
        $this->client->method('refund')->willThrowException(new LocalizedException(__('Binance down')));

        try {
            $this->command->execute(['payment' => $this->paymentDataObject($payment), 'amount' => 10.0]);
            $this->fail('The exception should reach Magento so the credit memo is not saved.');
        } catch (LocalizedException $e) {
            $this->assertArrayNotHasKey(PaymentInfo::REFUND_COUNT, $this->info);
        }
    }

    /**
     * Payment with additional information backed by $this->info.
     *
     * @param float $baseGrandTotal
     * @param float $baseTotalRefunded
     * @param bool $invoiceCanRefund
     * @return array{0: Payment|MockObject, 1: Order|MockObject}
     */
    private function payment(float $baseGrandTotal, float $baseTotalRefunded, bool $invoiceCanRefund): array
    {
        $order = $this->createMock(Order::class);
        $order->method('getBaseGrandTotal')->willReturn($baseGrandTotal);
        $order->method('getBaseTotalRefunded')->willReturn($baseTotalRefunded);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getStoreId')->willReturn(1);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('canRefund')->willReturn($invoiceCanRefund);
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getInvoice')->willReturn($invoice);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCreditmemo')->willReturn($creditmemo);
        $payment->method('getAdditionalInformation')->willReturnCallback(fn($key) => $this->info[$key] ?? null);
        $payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) use (&$payment) {
            $this->info[$key] = $value;
            return $payment;
        });

        return [$payment, $order];
    }

    /**
     * @param Payment $payment
     * @return PaymentDataObjectInterface|MockObject
     */
    private function paymentDataObject(Payment $payment)
    {
        $paymentDataObject = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDataObject->method('getPayment')->willReturn($payment);

        return $paymentDataObject;
    }
}
