<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Block;

use JoePham\BinancePay\Model\PaymentInfo;
use Magento\Framework\App\Area;
use Magento\Payment\Block\ConfigurableInfo;

/**
 * Binance Pay details on the order view (admin, customer account, emails).
 */
class Info extends ConfigurableInfo
{
    /**
     * Show the Binance IDs (privateInfoKeys) to the merchant in the admin, not to shoppers.
     *
     * Magento's default treats every order placed in a store view as secure, which hides them in the admin too.
     * Emails and PDFs set secure mode explicitly, so they still leave the IDs out.
     *
     * @return bool
     */
    public function getIsSecureMode()
    {
        if ($this->hasIsSecureMode()) {
            return parent::getIsSecureMode();
        }

        return $this->_appState->getAreaCode() !== Area::AREA_ADMINHTML;
    }

    /**
     * @inheritdoc
     */
    protected function getLabel($field)
    {
        $labels = [
            PaymentInfo::ORDER_AMOUNT => __('Binance Pay Amount'),
            PaymentInfo::PAID_WITH => __('Paid With'),
            PaymentInfo::MERCHANT_TRADE_NO => __('Merchant Trade No'),
            PaymentInfo::PREPAY_ID => __('Binance Prepay ID'),
            PaymentInfo::TRANSACTION_ID => __('Binance Transaction ID'),
        ];

        return $labels[$field] ?? $field;
    }
}
