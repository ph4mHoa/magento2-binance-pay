<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Model\Api;

use JoePham\BinancePay\Model\Api\Client;
use JoePham\BinancePay\Model\Api\Signer;
use JoePham\BinancePay\Model\Api\WebhookVerifier;
use JoePham\BinancePay\Model\Config;
use Magento\Framework\App\CacheInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebhookVerifierTest extends TestCase
{
    /**
     * @var Client|MockObject
     */
    private $client;

    /**
     * @var array in-memory cache
     */
    private array $cacheData = [];

    /**
     * @var string
     */
    private string $privateKey;

    /**
     * @var string
     */
    private string $publicKey;

    /**
     * @var WebhookVerifier
     */
    private WebhookVerifier $verifier;

    protected function setUp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privateKey = '';
        openssl_pkey_export($key, $privateKey);
        $this->privateKey = $privateKey;
        $this->publicKey = openssl_pkey_get_details($key)['key'];

        $this->client = $this->createMock(Client::class);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn($id) => $this->cacheData[$id] ?? false);
        $cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->cacheData[$id] = $data;
            return true;
        });
        $config = $this->createMock(Config::class);
        $config->method('getApiUrl')->willReturn('https://bpay.binanceapi.com');
        $config->method('getApiKey')->willReturn('key');

        $this->verifier = new WebhookVerifier($this->client, new Signer(), $cache, $config);
    }

    public function testValidSignatureIsAcceptedAndCertificatesAreCached(): void
    {
        $this->client->expects($this->once())->method('getCertificates')
            ->willReturn([['certSerial' => 'abc123', 'certPublic' => $this->publicKey]]);
        $body = '{"bizType":"PAY","bizStatus":"PAY_SUCCESS","data":"{}"}';

        $signature = $this->sign('1700000000000', 'nonce', $body);

        $this->assertTrue($this->verifier->verify($body, '1700000000000', 'nonce', $signature, 'abc123'));
        $this->assertTrue($this->verifier->verify($body, '1700000000000', 'nonce', $signature, 'ABC123'));
    }

    public function testWrongSignatureIsRejected(): void
    {
        $this->client->method('getCertificates')
            ->willReturn([['certSerial' => 'abc123', 'certPublic' => $this->publicKey]]);
        $body = '{"bizType":"PAY"}';

        $signature = $this->sign('1700000000000', 'nonce', $body);

        $this->assertFalse($this->verifier->verify($body . ' ', '1700000000000', 'nonce', $signature, 'abc123'));
        $this->assertFalse($this->verifier->verify($body, '1700000000001', 'nonce', $signature, 'abc123'));
    }

    public function testUnknownSerialRefetchesOnlyOnceInFiveMinutes(): void
    {
        $this->client->expects($this->once())->method('getCertificates')
            ->willReturn([['certSerial' => 'abc123', 'certPublic' => $this->publicKey]]);
        $body = '{}';

        $signature = $this->sign('1', 'n', $body);

        $this->assertFalse($this->verifier->verify($body, '1', 'n', $signature, 'other'));
        $this->assertFalse($this->verifier->verify($body, '1', 'n', $signature, 'other'));
    }

    public function testMissingHeadersAreRejectedWithoutApiCalls(): void
    {
        $this->client->expects($this->never())->method('getCertificates');

        $this->assertFalse($this->verifier->verify('{}', '', 'n', 'sig', 'abc'));
        $this->assertFalse($this->verifier->verify('', '1', 'n', 'sig', 'abc'));
    }

    /**
     * Sign like Binance does.
     *
     * @param string $timestamp
     * @param string $nonce
     * @param string $body
     * @return string
     */
    private function sign(string $timestamp, string $nonce, string $body): string
    {
        $signature = '';
        openssl_sign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }
}
