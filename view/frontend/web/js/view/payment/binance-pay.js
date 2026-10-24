/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'joepham_binancepay',
        component: 'JoePham_BinancePay/js/view/payment/method-renderer/binance-pay'
    });

    return Component.extend({});
});
