<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Model;

use JoePham\BinancePay\Model\Api\ApiException;
use JoePham\BinancePay\Model\Api\Client;
use JoePham\BinancePay\Model\Config;
use JoePham\BinancePay\Model\OrderLocker;
use JoePham\BinancePay\Model\OrderSync;
use JoePham\BinancePay\Model\PaymentInfo;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderSyncTest extends TestCase
{
    /**
     * @var Client|MockObject
     */
    private $client;

    /**
     * @var OrderRepositoryInterface|MockObject
     */
    private $orderRepository;

    /**
     * @var OrderSender|MockObject
     */
    private $orderSender;

    /**
     * @var OrderStatusHistoryRepositoryInterface|MockObject
     */
    private $historyRepository;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var Order|MockObject
     */
    private $order;

    /**
     * @var Payment|MockObject
     */
    private $payment;

    /**
     * @var array payment additional information
     */
    private array $info = [];

    /**
     * @var OrderSync
     */
    private OrderSync $sync;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->historyRepository = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->info = [
            PaymentInfo::MERCHANT_TRADE_NO => 'STG000000123',
            PaymentInfo::PREPAY_ID => '2900000000000001',
            PaymentInfo::TOTAL_FEE => '25.01000000',
            PaymentInfo::CURRENCY => 'USDT',
        ];
        $this->payment = $this->createMock(Payment::class);
        $this->payment->method('getMethod')->willReturn(Config::METHOD_CODE);
        $this->payment->method('getAdditionalInformation')->willReturnCallback(fn($key) => $this->info[$key] ?? null);
        $this->payment->method('setAdditionalInformation')->willReturnCallback(function ($key, $value) {
            $this->info[$key] = $value;
            return $this->payment;
        });

        $this->order = $this->createMock(Order::class);
        $this->order->method('getId')->willReturn(42);
        $this->order->method('getStoreId')->willReturn(1);
        $this->order->method('getIncrementId')->willReturn('000000123');
        $this->order->method('getPayment')->willReturn($this->payment);
        $this->order->method('getBaseGrandTotal')->willReturn(25.0);
        $this->order->method('canHold')->willReturn(true);

        $orderFactory = $this->createMock(OrderFactory::class);
        $orderFactory->method('create')->willReturn($this->order);
        $locker = $this->createMock(OrderLocker::class);
        $locker->method('run')->willReturnCallback(fn(int $orderId, callable $callback) => $callback());

        $this->sync = new OrderSync(
            $this->client,
            $locker,
            $orderFactory,
            $this->createMock(OrderResource::class),
            $this->orderRepository,
            $this->orderSender,
            $this->historyRepository,
            $this->logger
        );
    }

    public function testPaidOrderIsInvoicedAndTheOrderEmailSent(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->with('STG000000123', 1)->willReturn([
            'status' => 'PAID',
            'transactionId' => 'M_P_1',
            'orderAmount' => '25.01',
            'currency' => 'USDT',
            'paymentInfo' => ['paymentInstructions' => [['currency' => 'BNB', 'amount' => '0.04', 'price' => '600']]],
        ]);

        $this->payment->expects($this->once())->method('setTransactionId')->with('M_P_1');
        $this->payment->expects($this->once())->method('registerCaptureNotification')->with(25.0, true);
        $this->orderRepository->expects($this->once())->method('save')->with($this->order);
        $this->orderSender->expects($this->once())->method('send')->with($this->order);
        $this->order->expects($this->never())->method('hold');

        $this->sync->sync(42);

        $this->assertSame('M_P_1', $this->info[PaymentInfo::TRANSACTION_ID]);
        $this->assertSame('0.04 BNB', $this->info[PaymentInfo::PAID_WITH]);
    }

    public function testPaymentForAnotherAmountHoldsTheOrderInsteadOfInvoicing(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn([
            'status' => 'PAID',
            'transactionId' => 'M_P_1',
            'orderAmount' => '1.00',
            'currency' => 'USDT',
        ]);

        $this->payment->expects($this->never())->method('registerCaptureNotification');
        $this->order->expects($this->once())->method('hold');
        $this->logger->expects($this->once())->method('critical');
        $this->orderSender->expects($this->never())->method('send');

        $this->sync->sync(42);
    }

    public function testPaymentInAnotherCurrencyHoldsTheOrder(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn([
            'status' => 'PAID',
            'orderAmount' => '25.01',
            'currency' => 'BUSD',
        ]);

        $this->payment->expects($this->never())->method('registerCaptureNotification');
        $this->order->expects($this->once())->method('hold');

        $this->sync->sync(42);
    }

    /**
     * @dataProvider closedStatuses
     */
    public function testClosedBinanceOrderCancelsTheMagentoOrder(string $status): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn(['status' => $status]);

        $this->order->expects($this->once())->method('cancel');
        $this->orderRepository->expects($this->once())->method('save');

        $this->sync->sync(42);
    }

    public static function closedStatuses(): array
    {
        return [['CANCELED'], ['EXPIRED'], ['ERROR']];
    }

    public function testUnpaidBinanceOrderIsLeftAlone(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn(['status' => 'INITIAL']);

        $this->order->expects($this->never())->method('cancel');
        $this->orderRepository->expects($this->never())->method('save');

        $this->sync->sync(42);
    }

    public function testRefundedBinanceOrderThatMagentoNeverInvoicedIsHeld(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn(['status' => 'REFUNDED']);

        $this->order->expects($this->once())->method('hold');
        $this->payment->expects($this->never())->method('registerCaptureNotification');

        $this->sync->sync(42);
    }

    public function testOrderThatIsNoLongerPendingIsNotTouched(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PROCESSING);

        $this->client->expects($this->never())->method('queryOrder');
        $this->orderRepository->expects($this->never())->method('save');

        $this->sync->sync(42);
    }

    public function testOrderThatNeverReachedBinanceIsCancelledAfterAnHour(): void
    {
        unset($this->info[PaymentInfo::MERCHANT_TRADE_NO]);
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->order->method('getCreatedAt')->willReturn(gmdate('Y-m-d H:i:s', time() - 3700));

        $this->client->expects($this->never())->method('queryOrder');
        $this->order->expects($this->once())->method('cancel');

        $this->sync->sync(42);
    }

    public function testRecentOrderThatHasNotReachedBinanceYetIsKept(): void
    {
        unset($this->info[PaymentInfo::MERCHANT_TRADE_NO]);
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->order->method('getCreatedAt')->willReturn(gmdate('Y-m-d H:i:s', time() - 60));

        $this->order->expects($this->never())->method('cancel');

        $this->sync->sync(42);
    }

    public function testAbandonClosesAnOpenBinanceOrderAndCancels(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn(['status' => 'INITIAL']);

        $this->client->expects($this->once())->method('closeOrder')->with('STG000000123', 1);
        $this->order->expects($this->once())->method('cancel');

        $this->sync->abandon(42);
    }

    public function testAbandonAfterAPaymentWentThroughInvoicesInstead(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn([
            'status' => 'PAID',
            'prepayId' => '2900000000000001',
            'orderAmount' => '25.01000000',
            'currency' => 'USDT',
        ]);

        $this->client->expects($this->never())->method('closeOrder');
        $this->order->expects($this->never())->method('cancel');
        $this->payment->expects($this->once())->method('setTransactionId')->with('2900000000000001');
        $this->payment->expects($this->once())->method('registerCaptureNotification');

        $this->sync->abandon(42);
    }

    public function testAbandonKeepsTheOrderWhenBinanceRefusesToClose(): void
    {
        $this->order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->client->method('queryOrder')->willReturn(['status' => 'PENDING']);
        $this->client->method('closeOrder')->willThrowException(
            new ApiException(__('Order is being paid'))
        );

        $this->order->expects($this->never())->method('cancel');
        $this->expectException(ApiException::class);

        $this->sync->abandon(42);
    }

    public function testRefundWebhookSavesOnlyTheComment(): void
    {
        $history = $this->createMock(History::class);
        $this->order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(fn($phrase) => (string)$phrase === 'Binance Pay completed refund R1 of 10 USDT.'))
            ->willReturn($history);
        $this->historyRepository->expects($this->once())->method('save')->with($history);
        $this->orderRepository->expects($this->never())->method('save');

        $this->sync->addRefundNotice(42, 'REFUND_SUCCESS', ['refundRequestId' => 'R1', 'refundAmount' => '10']);
    }
}
