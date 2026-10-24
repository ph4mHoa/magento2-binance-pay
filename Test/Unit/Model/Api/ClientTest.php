<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Model\Api;

use JoePham\BinancePay\Model\Api\ApiException;
use JoePham\BinancePay\Model\Api\Client;
use JoePham\BinancePay\Model\Api\Signer;
use JoePham\BinancePay\Model\Config;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClientTest extends TestCase
{
    /**
     * @var Curl|MockObject
     */
    private $curl;

    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var array{headers: array, url: string, body: string}
     */
    private array $sent = ['headers' => [], 'url' => '', 'body' => ''];

    /**
     * @var Client
     */
    private Client $client;

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('setHeaders')->willReturnCallback(function (array $headers) {
            $this->sent['headers'] = $headers;
        });
        $this->curl->method('post')->willReturnCallback(function (string $url, $body) {
            $this->sent['url'] = $url;
            $this->sent['body'] = $body;
        });
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiUrl')->willReturn('https://bpay.binanceapi.com');

        $this->client = new Client($this->config, $curlFactory, new Signer(), $this->createMock(LoggerInterface::class));
    }

    public function testRequestIsSignedWithTheSecretOverTheExactBodySent(): void
    {
        $this->configureCredentials();
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn(
            '{"status":"SUCCESS","code":"000000","data":{"prepayId":"383729303729303","checkoutUrl":"https://pay/x"}}'
        );

        $data = $this->client->createOrder(['merchantTradeNo' => 'T1', 'fiatAmount' => 10.0, 'description' => 'Café/1']);

        $this->assertSame('https://bpay.binanceapi.com/binancepay/openapi/v3/order', $this->sent['url']);
        $this->assertSame('{"merchantTradeNo":"T1","fiatAmount":10.0,"description":"Café/1"}', $this->sent['body']);
        $headers = $this->sent['headers'];
        $this->assertSame('api-key', $headers['BinancePay-Certificate-SN']);
        $this->assertMatchesRegularExpression('/^[a-zA-Z]{32}$/', $headers['BinancePay-Nonce']);
        $this->assertMatchesRegularExpression('/^\d{13}$/', $headers['BinancePay-Timestamp']);
        $this->assertSame(
            strtoupper(hash_hmac(
                'sha512',
                $headers['BinancePay-Timestamp'] . "\n" . $headers['BinancePay-Nonce'] . "\n" . $this->sent['body'] . "\n",
                'api-secret'
            )),
            $headers['BinancePay-Signature']
        );
        $this->assertSame('383729303729303', $data['prepayId']);
    }

    public function testEmptyPayloadIsSentAsAnEmptyObject(): void
    {
        $this->configureCredentials();
        $this->curl->method('getBody')->willReturn('{"status":"SUCCESS","data":[]}');

        $this->client->getCertificates();

        $this->assertSame('{}', $this->sent['body']);
    }

    public function testIdsBeyondPhpIntegersStayExact(): void
    {
        $this->configureCredentials();
        $this->curl->method('getBody')->willReturn(
            '{"status":"SUCCESS","data":{"prepayId":29383937493038367292,"status":"PAID"}}'
        );

        $this->assertSame('29383937493038367292', $this->client->queryOrder('T1')['prepayId']);
    }

    public function testErrorResponseThrowsWithBinanceCode(): void
    {
        $this->configureCredentials();
        $this->curl->method('getStatus')->willReturn(400);
        $this->curl->method('getBody')->willReturn(
            '{"status":"FAIL","code":"400201","errorMessage":"merchantTradeNo is invalid or duplicated"}'
        );

        try {
            $this->client->createOrder(['merchantTradeNo' => 'T1']);
            $this->fail('An ApiException was expected.');
        } catch (ApiException $e) {
            $this->assertSame('400201', $e->getResultCode());
            $this->assertStringContainsString('merchantTradeNo is invalid', $e->getMessage());
        }
    }

    public function testNonJsonResponseThrows(): void
    {
        $this->configureCredentials();
        $this->curl->method('getStatus')->willReturn(502);
        $this->curl->method('getBody')->willReturn('<html>Bad gateway</html>');

        $this->expectException(ApiException::class);
        $this->client->queryOrder('T1');
    }

    public function testMissingCredentialsStopBeforeAnyRequest(): void
    {
        $this->config->method('getApiKey')->willReturn('');
        $this->config->method('getApiSecret')->willReturn('');
        $this->curl->expects($this->never())->method('post');

        $this->expectException(ApiException::class);
        $this->client->queryOrder('T1');
    }

    /**
     * @return void
     */
    private function configureCredentials(): void
    {
        $this->config->method('getApiKey')->willReturn('api-key');
        $this->config->method('getApiSecret')->willReturn('api-secret');
    }
}
