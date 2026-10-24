<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Controller\Webhook;

use JoePham\BinancePay\Controller\Webhook\Index;
use JoePham\BinancePay\Model\Api\ApiException;
use JoePham\BinancePay\Model\Api\WebhookVerifier;
use JoePham\BinancePay\Model\OrderLocator;
use JoePham\BinancePay\Model\OrderSync;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class IndexTest extends TestCase
{
    private const PAY_BODY = '{"bizType":"PAY","bizId":29383937493038367292,"bizIdStr":"29383937493038367292",'
        . '"bizStatus":"PAY_SUCCESS","data":"{\"merchantTradeNo\":\"STG000000123\",'
        . '\"passThroughInfo\":\"42\",\"totalFee\":0.88,\"currency\":\"USDT\"}"}';

    /**
     * @var HttpRequest|MockObject
     */
    private $request;

    /**
     * @var WebhookVerifier|MockObject
     */
    private $verifier;

    /**
     * @var OrderLocator|MockObject
     */
    private $orderLocator;

    /**
     * @var OrderSync|MockObject
     */
    private $orderSync;

    /**
     * @var array{code: int|null, data: array|null} captured response
     */
    private array $response = ['code' => null, 'data' => null];

    /**
     * @var Index
     */
    private Index $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(HttpRequest::class);
        $this->request->method('getHeader')->willReturnMap([
            ['BinancePay-Timestamp', false, '1700000000000'],
            ['BinancePay-Nonce', false, 'abcdefghijklmnopqrstuvwxyzABCDEF'],
            ['BinancePay-Signature', false, 'c2lnbmF0dXJl'],
            ['BinancePay-Certificate-SN', false, 'serial'],
        ]);
        $this->verifier = $this->createMock(WebhookVerifier::class);
        $this->orderLocator = $this->createMock(OrderLocator::class);
        $this->orderSync = $this->createMock(OrderSync::class);

        $result = $this->createMock(Json::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($result) {
            $this->response['code'] = $code;
            return $result;
        });
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response['data'] = $data;
            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        $this->controller = new Index(
            $this->request,
            $jsonFactory,
            $this->verifier,
            $this->orderLocator,
            $this->orderSync,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testSignedPaymentNotificationSyncsTheOrder(): void
    {
        $this->request->method('getContent')->willReturn(self::PAY_BODY);
        $this->orderLocator->method('locate')->with('STG000000123', 42)->willReturn($this->order());
        $this->verifier->expects($this->once())->method('verify')
            ->with(self::PAY_BODY, '1700000000000', 'abcdefghijklmnopqrstuvwxyzABCDEF', 'c2lnbmF0dXJl', 'serial', 3)
            ->willReturn(true);
        $this->orderSync->expects($this->once())->method('sync')->with(42);

        $this->controller->execute();

        $this->assertSame(200, $this->response['code']);
        $this->assertSame(['returnCode' => 'SUCCESS', 'returnMessage' => null], $this->response['data']);
    }

    public function testBadSignatureIsRejectedAndChangesNothing(): void
    {
        $this->request->method('getContent')->willReturn(self::PAY_BODY);
        $this->orderLocator->method('locate')->willReturn($this->order());
        $this->verifier->method('verify')->willReturn(false);
        $this->orderSync->expects($this->never())->method('sync');

        $this->controller->execute();

        $this->assertSame(401, $this->response['code']);
        $this->assertSame('FAIL', $this->response['data']['returnCode']);
    }

    public function testNotificationForAnUnknownOrderIsAcknowledged(): void
    {
        $this->request->method('getContent')->willReturn(self::PAY_BODY);
        $this->orderLocator->method('locate')->willReturn(null);
        $this->verifier->expects($this->once())->method('verify')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), null)
            ->willReturn(true);
        $this->orderSync->expects($this->never())->method('sync');

        $this->controller->execute();

        $this->assertSame(200, $this->response['code']);
        $this->assertSame('SUCCESS', $this->response['data']['returnCode']);
    }

    public function testRefundNotificationAddsARefundNotice(): void
    {
        $body = '{"bizType":"PAY_REFUND","bizIdStr":"1","bizStatus":"REFUND_SUCCESS","data":"{\"merchantTradeNo\":'
            . '\"STG000000123\",\"refundInfo\":{\"refundRequestId\":\"STG000000123R1\",\"refundAmount\":\"10\"}}"}';
        $this->request->method('getContent')->willReturn($body);
        $this->orderLocator->method('locate')->with('STG000000123', null)->willReturn($this->order());
        $this->verifier->method('verify')->willReturn(true);
        $this->orderSync->expects($this->never())->method('sync');
        $this->orderSync->expects($this->once())->method('addRefundNotice')
            ->with(42, 'REFUND_SUCCESS', ['refundRequestId' => 'STG000000123R1', 'refundAmount' => '10']);

        $this->controller->execute();

        $this->assertSame(200, $this->response['code']);
    }

    public function testMalformedBodyIsRejected(): void
    {
        $this->request->method('getContent')->willReturn('not json');
        $this->verifier->expects($this->never())->method('verify');

        $this->controller->execute();

        $this->assertSame(400, $this->response['code']);
    }

    public function testCertificateOutageAsksBinanceToRetry(): void
    {
        $this->request->method('getContent')->willReturn(self::PAY_BODY);
        $this->orderLocator->method('locate')->willReturn($this->order());
        $this->verifier->method('verify')->willThrowException(new ApiException(__('timeout')));

        $this->controller->execute();

        $this->assertSame(500, $this->response['code']);
        $this->assertSame('FAIL', $this->response['data']['returnCode']);
    }

    public function testSyncFailureAsksBinanceToRetry(): void
    {
        $this->request->method('getContent')->willReturn(self::PAY_BODY);
        $this->orderLocator->method('locate')->willReturn($this->order());
        $this->verifier->method('verify')->willReturn(true);
        $this->orderSync->method('sync')->willThrowException(new ApiException(__('timeout')));

        $this->controller->execute();

        $this->assertSame(500, $this->response['code']);
    }

    /**
     * @return Order|MockObject
     */
    private function order()
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getStoreId')->willReturn(3);
        $order->method('getIncrementId')->willReturn('000000123');

        return $order;
    }
}
