<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;

/**
 * Builds the Create Order (v3) request for a Magento order.
 *
 * The order is priced in the store currency (fiatCurrency/fiatAmount); Binance converts it to USDT or USDC
 * at the market rate and returns the crypto amount as totalFee.
 */
class OrderRequestBuilder
{
    private const TEXT_LIMIT = 256;
    private const TRADE_NO_LIMIT = 32;
    private const GOODS_CATEGORY_OTHERS = 'Z000';
    private const GOODS_TYPE_TANGIBLE = '01';
    private const GOODS_TYPE_VIRTUAL = '02';

    /**
     * @param Config $config
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly Config $config,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * Binance merchantTradeNo for an order: prefix + increment ID, letters and digits only, 32 chars max.
     *
     * @param Order $order
     * @return string
     * @throws LocalizedException
     */
    public function createMerchantTradeNo(Order $order): string
    {
        $tradeNo = $this->config->getTradeNoPrefix((int)$order->getStoreId())
            . preg_replace('/[^A-Za-z0-9]/', '', (string)$order->getIncrementId());
        if ($tradeNo === '' || strlen($tradeNo) > self::TRADE_NO_LIMIT) {
            throw new LocalizedException(
                __('Order number "%1" cannot be used as a Binance Pay order number.', $tradeNo)
            );
        }

        return $tradeNo;
    }

    /**
     * Create Order (v3) request body.
     *
     * @param Order $order
     * @param string $merchantTradeNo
     * @return array
     */
    public function build(Order $order, string $merchantTradeNo): array
    {
        $storeId = (int)$order->getStoreId();
        $incrementId = (string)$order->getIncrementId();
        $itemNames = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $itemNames[] = sprintf('%s x %s', $item->getName(), (float)$item->getQtyOrdered());
        }

        return [
            'env' => ['terminalType' => 'WEB'],
            'merchantTradeNo' => $merchantTradeNo,
            'fiatAmount' => round((float)$order->getGrandTotal(), 2),
            'fiatCurrency' => strtoupper((string)$order->getOrderCurrencyCode()),
            'description' => $this->clean(
                sprintf('Order %s - %s', $incrementId, (string)$order->getStore()->getFrontendName())
            ),
            'goodsDetails' => [
                [
                    'goodsType' => $order->getIsVirtual() ? self::GOODS_TYPE_VIRTUAL : self::GOODS_TYPE_TANGIBLE,
                    'goodsCategory' => self::GOODS_CATEGORY_OTHERS,
                    'referenceGoodsId' => $merchantTradeNo,
                    'goodsName' => $this->clean('Order ' . $incrementId),
                    'goodsDetail' => $this->clean(implode(', ', $itemNames)),
                ],
            ],
            'returnUrl' => $this->url('joepham_binancepay/checkout/complete', $storeId),
            'cancelUrl' => $this->url('joepham_binancepay/checkout/cancel', $storeId),
            'webhookUrl' => $this->getWebhookUrl($storeId),
            'passThroughInfo' => (string)$order->getEntityId(),
        ];
    }

    /**
     * Where Binance sends order and refund notifications for this store.
     *
     * @param int $storeId
     * @return string
     */
    public function getWebhookUrl(int $storeId): string
    {
        return $this->url('joepham_binancepay/webhook', $storeId);
    }

    /**
     * Binance rejects quotes, backslashes and emoji in names; keep letters, digits and common punctuation.
     *
     * @param string $text
     * @return string
     */
    private function clean(string $text): string
    {
        $text = (string)preg_replace('/[^\p{L}\p{N} .,:;#()&\/+\-_]/u', ' ', $text);
        $text = trim((string)preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text !== '' ? $text : 'Order', 0, self::TEXT_LIMIT);
    }

    /**
     * Absolute URL in the order's store, without query parameters (Binance allows at most one).
     *
     * @param string $route
     * @param int $storeId
     * @return string
     */
    private function url(string $route, int $storeId): string
    {
        return $this->urlBuilder->getUrl($route, ['_scope' => $storeId, '_secure' => true, '_nosid' => true]);
    }
}
