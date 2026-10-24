<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model\Api;

use JoePham\BinancePay\Model\Config;
use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Binance Pay Merchant API client.
 *
 * @see https://developers.binance.com/en/docs/products/binance-pay-merchant/api-common
 */
class Client
{
    private const PATH_CREATE_ORDER = '/binancepay/openapi/v3/order';
    private const PATH_QUERY_ORDER = '/binancepay/openapi/v2/order/query';
    private const PATH_CLOSE_ORDER = '/binancepay/openapi/order/close';
    private const PATH_REFUND = '/binancepay/openapi/order/refund';
    private const PATH_CERTIFICATES = '/binancepay/openapi/certificates';

    private const TIMEOUT = 30;

    /**
     * @param Config $config
     * @param CurlFactory $curlFactory
     * @param Signer $signer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly CurlFactory $curlFactory,
        private readonly Signer $signer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Create Order (v3). Returns the OrderResult: prepayId, checkoutUrl, totalFee, currency, expireTime...
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     * @throws ApiException
     */
    public function createOrder(array $request, ?int $storeId = null): array
    {
        return (array)$this->call(self::PATH_CREATE_ORDER, $request, $storeId);
    }

    /**
     * Query Order (v2).
     *
     * Returns status (INITIAL, PENDING, PAID, CANCELED, ERROR, REFUNDING, REFUNDED, FULL_REFUNDED, EXPIRED),
     * orderAmount, currency, transactionId, paymentInfo...
     *
     * @param string $merchantTradeNo
     * @param int|null $storeId
     * @return array
     * @throws ApiException
     */
    public function queryOrder(string $merchantTradeNo, ?int $storeId = null): array
    {
        return (array)$this->call(self::PATH_QUERY_ORDER, ['merchantTradeNo' => $merchantTradeNo], $storeId);
    }

    /**
     * Close Order. Binance confirms asynchronously with a PAY_CLOSED webhook.
     *
     * @param string $merchantTradeNo
     * @param int|null $storeId
     * @return void
     * @throws ApiException
     */
    public function closeOrder(string $merchantTradeNo, ?int $storeId = null): void
    {
        $this->call(self::PATH_CLOSE_ORDER, ['merchantTradeNo' => $merchantTradeNo], $storeId);
    }

    /**
     * Refund Order. Returns refundId, refundStatus, refundedAmount...
     *
     * @param array $request
     * @param int|null $storeId
     * @return array
     * @throws ApiException
     */
    public function refund(array $request, ?int $storeId = null): array
    {
        return (array)$this->call(self::PATH_REFUND, $request, $storeId);
    }

    /**
     * Public keys Binance signs webhooks with: [['certSerial' => ..., 'certPublic' => ...], ...].
     *
     * @param int|null $storeId
     * @return array
     * @throws ApiException
     */
    public function getCertificates(?int $storeId = null): array
    {
        return (array)$this->call(self::PATH_CERTIFICATES, [], $storeId);
    }

    /**
     * Send a signed request and return the "data" part of a SUCCESS response.
     *
     * @param string $path
     * @param array $payload
     * @param int|null $storeId
     * @return mixed
     * @throws ApiException
     */
    private function call(string $path, array $payload, ?int $storeId)
    {
        $apiKey = $this->config->getApiKey($storeId);
        $secret = $this->config->getApiSecret($storeId);
        if ($apiKey === '' || $secret === '') {
            throw new ApiException(__('Binance Pay API key and secret are not configured.'));
        }

        // The exact string that is signed must be the one that is sent.
        $body = (string)json_encode(
            $payload ?: new \stdClass(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $timestamp = (string)(int)round(microtime(true) * 1000);
        $nonce = $this->signer->createNonce();
        $url = $this->config->getApiUrl($storeId) . $path;

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT);
        $curl->setHeaders([
            'Content-Type' => 'application/json',
            'BinancePay-Timestamp' => $timestamp,
            'BinancePay-Nonce' => $nonce,
            'BinancePay-Certificate-SN' => $apiKey,
            'BinancePay-Signature' => $this->signer->sign($timestamp, $nonce, $body, $secret),
        ]);

        try {
            $curl->post($url, $body);
        } catch (\Exception $e) {
            $this->logger->error('Binance Pay request failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new ApiException(__('Could not connect to Binance Pay: %1', $e->getMessage()), '', $e);
        }

        $status = (int)$curl->getStatus();
        $raw = (string)$curl->getBody();
        if ($this->config->isDebug($storeId)) {
            $this->logger->debug('Binance Pay API call', [
                'path' => $path,
                'request' => $body,
                'status' => $status,
                'response' => $raw,
            ]);
        }

        // Big IDs (prepayId, bizId) can exceed PHP_INT_MAX.
        $response = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        if (!is_array($response)) {
            throw new ApiException(__('Unexpected response from Binance Pay (HTTP %1).', $status), (string)$status);
        }
        if (($response['status'] ?? '') !== 'SUCCESS') {
            $code = (string)($response['code'] ?? $status);
            $message = (string)($response['errorMessage'] ?? $response['msg'] ?? $response['errorData'] ?? '');
            $this->logger->warning('Binance Pay API error', ['path' => $path, 'code' => $code, 'message' => $message]);
            throw new ApiException(__('Binance Pay error %1: %2', $code, $message), $code);
        }

        return $response['data'] ?? null;
    }
}
