/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
define([
    'Magento_Checkout/js/view/payment/default',
    'mage/url'
], function (Component, url) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'JoePham_BinancePay/payment/binance-pay'
        },

        /** The Binance order is created after the Magento order, by the redirect controller. */
        redirectAfterPlaceOrder: false,

        /**
         * Send the shopper to the Binance Pay checkout.
         */
        afterPlaceOrder: function () {
            window.location.replace(url.build('joepham_binancepay/checkout/redirect'));
        }
    });
});
