<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A failed Binance Pay API call, carrying the Binance result code (for example 400201).
 */
class ApiException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param string $resultCode
     * @param \Exception|null $cause
     */
    public function __construct(
        Phrase $phrase,
        private readonly string $resultCode = '',
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * Binance Pay result code, or the HTTP status when the response was not JSON.
     *
     * @return string
     */
    public function getResultCode(): string
    {
        return $this->resultCode;
    }
}
