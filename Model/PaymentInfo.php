<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

/**
 * Keys of the Binance data kept in the order payment's additional_information.
 */
class PaymentInfo
{
    public const MERCHANT_TRADE_NO = 'binance_merchant_trade_no';
    public const PREPAY_ID = 'binance_prepay_id';
    public const CHECKOUT_URL = 'binance_checkout_url';
    public const EXPIRE_TIME = 'binance_expire_time';
    public const TOTAL_FEE = 'binance_total_fee';
    public const CURRENCY = 'binance_currency';
    public const ORDER_AMOUNT = 'binance_order_amount';
    public const TRANSACTION_ID = 'binance_transaction_id';
    public const PAID_WITH = 'binance_paid_with';
    public const REFUNDED_AMOUNT = 'binance_refunded_amount';
    public const REFUND_COUNT = 'binance_refund_count';
}
