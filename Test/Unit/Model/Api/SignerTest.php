<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Model\Api;

use JoePham\BinancePay\Model\Api\Signer;
use PHPUnit\Framework\TestCase;

/**
 * Test vectors from the Binance Pay merchant FAQ (developers.binance.com/en/docs/products/binance-pay-merchant/fap).
 */
class SignerTest extends TestCase
{
    private const TIMESTAMP = '1611232922428';
    private const NONCE = '5RhaTrZPhknNv0kDSA2UQ67cPMVNS4sA';
    private const SECRET = 'uahr4nhailyq55yubuhuncnprfac73bcbz2qfafsevic1jruwunioxtoaozy122f';
    private const REQUEST_BODY = '{"merchantId":"98765987","subMerchantId":"98765987","merchantTradeNo":"9825382937292",'
        . '"totalFee":25.17,"productDetail":"Greentea ice cream cone","currency":"USDT","returnUrl":"",'
        . '"tradeType":"APP","productType":"Food","productName":"Ice Cream"}';

    private const WEBHOOK_BODY = '{"bizType":"PAY","data":"{\"merchantTradeNo\":\"9825382937292\",'
        . '\"totalFee\":0.88000000,\"transactTime\":1619508939664,\"currency\":\"EUR\",\"commission\":0,'
        . '\"openUserId\":\"1211HS10K81f4273ac031\",\"productType\":\"Food\",\"productName\":\"Ice Cream\",'
        . '\"tradeType\":\"WEB\",\"transactionId\":\"M_R_282737362839373\"}","bizId":29383937493038367292,'
        . '"bizStatus":"PAY_SUCCESS"}';
    private const WEBHOOK_SIGNATURE = 'VtniwOFAi4oBKFnvHhY6UZ+wPARf7+yWVbE61Hc7JVdRgqKJ40Yk9k5Gb2Y0S0VLUYOOOhUNUsD7'
        . 'R8GDEr6WH84sAEf8bXa1xYMiyjgeGleTg1MxRJCtrHcxMeMCEXPWmKTeLhn6O+cdvDGq4ZpcTTiQnEvk5xHPcxFrBT637zg=';
    private const PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\n"
        . "MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQCVAMXVTMIuttHcP1kvSk9V39S7\n"
        . "BqDbY+LC9JfwMzyoubo3fdsqBf4EmB1PfBUigcOL5YBqMKGEE6xmVw6SXCTH4JwX\n"
        . "dqJ4IRiXf76YMt6PB0dMzu/qCmhaGFGkqT6vJM0hHyEbtS/P3FR9ZU+eaOvynLWb\n"
        . "FvXTzIoctZM1IvDrCwIDAQAB\n"
        . "-----END PUBLIC KEY-----";

    /**
     * @var Signer
     */
    private Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new Signer();
    }

    /**
     * The FAQ prints a request signature that its own inputs do not produce, so the expected value comes from
     * the official Python connector (binance/binance-pay-connector-python, lib/utils.py hashing()) and the
     * official Postman pre-request script: HmacSHA512(ts\nnonce\nbody\n, secret), upper-case hex.
     */
    public function testSignMatchesTheOfficialConnector(): void
    {
        $this->assertSame(
            'C1F7A336D5E6A100DD31F41669895D99C24A3A4D4F96DB6389761CB2CDD37FA2'
            . 'F6D106A24DB048B4989005E85EADEA392CD866902736D3D276BDC44C49DD00FC',
            $this->signer->sign(self::TIMESTAMP, self::NONCE, self::REQUEST_BODY, self::SECRET)
        );
    }

    public function testVerifyAcceptsTheDocumentedWebhook(): void
    {
        $this->assertTrue($this->signer->verify(
            self::TIMESTAMP,
            self::NONCE,
            self::WEBHOOK_BODY,
            self::WEBHOOK_SIGNATURE,
            self::PUBLIC_KEY
        ));
    }

    public function testVerifyRejectsAChangedBody(): void
    {
        $this->assertFalse($this->signer->verify(
            self::TIMESTAMP,
            self::NONCE,
            str_replace('PAY_SUCCESS', 'PAY_CLOSED', self::WEBHOOK_BODY),
            self::WEBHOOK_SIGNATURE,
            self::PUBLIC_KEY
        ));
    }

    public function testVerifyRejectsAReformattedBody(): void
    {
        $reformatted = json_encode(json_decode(self::WEBHOOK_BODY, true, 512, JSON_BIGINT_AS_STRING), JSON_PRETTY_PRINT);

        $this->assertFalse($this->signer->verify(
            self::TIMESTAMP,
            self::NONCE,
            (string)$reformatted,
            self::WEBHOOK_SIGNATURE,
            self::PUBLIC_KEY
        ));
    }

    public function testVerifyRejectsAnotherTimestamp(): void
    {
        $this->assertFalse($this->signer->verify(
            '1611232922429',
            self::NONCE,
            self::WEBHOOK_BODY,
            self::WEBHOOK_SIGNATURE,
            self::PUBLIC_KEY
        ));
    }

    public function testVerifyRejectsMalformedInput(): void
    {
        $this->assertFalse($this->signer->verify(self::TIMESTAMP, self::NONCE, self::WEBHOOK_BODY, '%%%', self::PUBLIC_KEY));
        $this->assertFalse($this->signer->verify(self::TIMESTAMP, self::NONCE, self::WEBHOOK_BODY, '', self::PUBLIC_KEY));
        $this->assertFalse(
            $this->signer->verify(self::TIMESTAMP, self::NONCE, self::WEBHOOK_BODY, self::WEBHOOK_SIGNATURE, 'not a key')
        );
    }

    public function testNonceIs32AsciiLetters(): void
    {
        $nonce = $this->signer->createNonce();

        $this->assertMatchesRegularExpression('/^[A-Za-z]{32}$/', $nonce);
        $this->assertNotSame($nonce, $this->signer->createNonce());
    }
}
