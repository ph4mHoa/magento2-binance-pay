<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use Magento\Payment\Gateway\ConfigInterface;

/**
 * Typed access to the payment/joepham_binancepay settings.
 */
class Config
{
    public const METHOD_CODE = 'joepham_binancepay';

    private const DEFAULT_API_URL = 'https://bpay.binanceapi.com';

    /**
     * @param ConfigInterface $gatewayConfig
     */
    public function __construct(
        private readonly ConfigInterface $gatewayConfig
    ) {
    }

    /**
     * Binance Pay API host, without a trailing slash.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): string
    {
        $url = trim((string)$this->gatewayConfig->getValue('api_url', $storeId));

        return rtrim($url !== '' ? $url : self::DEFAULT_API_URL, '/');
    }

    /**
     * API identity key, sent as BinancePay-Certificate-SN.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string
    {
        return trim((string)$this->gatewayConfig->getValue('api_key', $storeId));
    }

    /**
     * API secret used for the HMAC-SHA512 request signature.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiSecret(?int $storeId = null): string
    {
        return trim((string)$this->gatewayConfig->getValue('api_secret', $storeId));
    }

    /**
     * Prefix for merchantTradeNo, letters and digits only.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getTradeNoPrefix(?int $storeId = null): string
    {
        return (string)preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            (string)$this->gatewayConfig->getValue('merchant_trade_no_prefix', $storeId)
        );
    }

    /**
     * Whether API traffic is written to the module log.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDebug(?int $storeId = null): bool
    {
        return (bool)$this->gatewayConfig->getValue('debug', $storeId);
    }
}
