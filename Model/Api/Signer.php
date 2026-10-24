<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model\Api;

/**
 * Binance Pay signatures.
 *
 * Requests are signed with HMAC-SHA512 of "timestamp\nnonce\nbody\n" using the API secret (upper-case hex).
 * Webhooks are signed by Binance with RSA-SHA256 over the same string built from the raw request body.
 */
class Signer
{
    private const NONCE_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Signature for the BinancePay-Signature request header.
     *
     * @param string $timestamp
     * @param string $nonce
     * @param string $body
     * @param string $secret
     * @return string
     */
    public function sign(string $timestamp, string $nonce, string $body, string $secret): string
    {
        return strtoupper(hash_hmac('sha512', $this->payload($timestamp, $nonce, $body), $secret));
    }

    /**
     * Check a webhook signature against a Binance Pay public key (PEM).
     *
     * @param string $timestamp
     * @param string $nonce
     * @param string $body raw request body, exactly as received
     * @param string $signature base64 value of the BinancePay-Signature header
     * @param string $publicKey
     * @return bool
     */
    public function verify(
        string $timestamp,
        string $nonce,
        string $body,
        string $signature,
        string $publicKey
    ): bool {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- Binance sends the RSA signature base64-encoded
        $decoded = base64_decode($signature, true);
        $key = openssl_pkey_get_public($publicKey);
        if ($decoded === false || $decoded === '' || $key === false) {
            return false;
        }

        return openssl_verify($this->payload($timestamp, $nonce, $body), $decoded, $key, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * Random 32-character nonce of ASCII letters, as the API requires.
     *
     * @return string
     */
    public function createNonce(): string
    {
        $nonce = '';
        $max = strlen(self::NONCE_CHARS) - 1;
        for ($i = 0; $i < 32; $i++) {
            $nonce .= self::NONCE_CHARS[random_int(0, $max)];
        }

        return $nonce;
    }

    /**
     * The string both signatures are computed over.
     *
     * @param string $timestamp
     * @param string $nonce
     * @param string $body
     * @return string
     */
    private function payload(string $timestamp, string $nonce, string $body): string
    {
        return $timestamp . "\n" . $nonce . "\n" . $body . "\n";
    }
}
