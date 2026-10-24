<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model\Api;

use JoePham\BinancePay\Model\Config;
use Magento\Framework\App\CacheInterface;

/**
 * Verifies webhook signatures with the public key from the Query Certificate API.
 *
 * Keys are cached for a day. An unknown BinancePay-Certificate-SN triggers one refetch, at most every
 * five minutes, so forged requests cannot turn into a stream of API calls.
 */
class WebhookVerifier
{
    private const CACHE_PREFIX = 'joepham_binancepay_certs_';
    private const CACHE_LIFETIME = 86400;
    private const REFETCH_AFTER = 300;

    /**
     * @param Client $client
     * @param Signer $signer
     * @param CacheInterface $cache
     * @param Config $config
     */
    public function __construct(
        private readonly Client $client,
        private readonly Signer $signer,
        private readonly CacheInterface $cache,
        private readonly Config $config
    ) {
    }

    /**
     * Check a webhook request.
     *
     * @param string $body raw request body
     * @param string $timestamp BinancePay-Timestamp header
     * @param string $nonce BinancePay-Nonce header
     * @param string $signature BinancePay-Signature header
     * @param string $certSerial BinancePay-Certificate-SN header
     * @param int|null $storeId store whose API credentials received the order
     * @return bool
     * @throws ApiException when the certificates cannot be fetched
     */
    public function verify(
        string $body,
        string $timestamp,
        string $nonce,
        string $signature,
        string $certSerial,
        ?int $storeId = null
    ): bool {
        if ($body === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            return false;
        }

        $publicKey = $this->getPublicKey($certSerial, $storeId);

        return $publicKey !== null && $this->signer->verify($timestamp, $nonce, $body, $signature, $publicKey);
    }

    /**
     * Find the public key for a certificate serial, refreshing the cached list when needed.
     *
     * @param string $certSerial
     * @param int|null $storeId
     * @return string|null
     * @throws ApiException
     */
    private function getPublicKey(string $certSerial, ?int $storeId): ?string
    {
        $cacheKey = self::CACHE_PREFIX
            . sha1($this->config->getApiUrl($storeId) . '|' . $this->config->getApiKey($storeId));
        $cached = json_decode((string)$this->cache->load($cacheKey), true);
        $certs = is_array($cached) ? (array)($cached['certs'] ?? []) : [];
        $fetchedAt = is_array($cached) ? (int)($cached['fetched_at'] ?? 0) : 0;

        $key = $this->findKey($certs, $certSerial);
        if ($key === null && time() - $fetchedAt > self::REFETCH_AFTER) {
            $certs = $this->client->getCertificates($storeId);
            $this->cache->save(
                (string)json_encode(['fetched_at' => time(), 'certs' => $certs]),
                $cacheKey,
                [],
                self::CACHE_LIFETIME
            );
            $key = $this->findKey($certs, $certSerial);
        }

        return $key;
    }

    /**
     * Pick the key whose certSerial matches; any key when the header is empty.
     *
     * @param array $certs
     * @param string $certSerial
     * @return string|null
     */
    private function findKey(array $certs, string $certSerial): ?string
    {
        foreach ($certs as $cert) {
            if (!is_array($cert) || empty($cert['certPublic'])) {
                continue;
            }
            if ($certSerial === '' || strcasecmp((string)($cert['certSerial'] ?? ''), $certSerial) === 0) {
                return (string)$cert['certPublic'];
            }
        }

        return null;
    }
}
