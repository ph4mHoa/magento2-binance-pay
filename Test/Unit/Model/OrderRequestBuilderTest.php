<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Test\Unit\Model;

use JoePham\BinancePay\Model\Config;
use JoePham\BinancePay\Model\OrderRequestBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderRequestBuilderTest extends TestCase
{
    /**
     * @var Config|MockObject
     */
    private $config;

    /**
     * @var OrderRequestBuilder
     */
    private OrderRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            fn(string $route, array $params) => 'https://shop.test/' . $route . '/?store=' . $params['_scope']
        );
        $this->builder = new OrderRequestBuilder($this->config, $urlBuilder);
    }

    public function testTradeNoIsPrefixPlusIncrementIdInLettersAndDigits(): void
    {
        $this->config->method('getTradeNoPrefix')->willReturn('STG');

        $this->assertSame('STG2000000123', $this->builder->createMerchantTradeNo($this->order('2-000000123')));
    }

    public function testTradeNoLongerThan32CharactersIsRefused(): void
    {
        $this->config->method('getTradeNoPrefix')->willReturn(str_repeat('P', 20));

        $this->expectException(LocalizedException::class);
        $this->builder->createMerchantTradeNo($this->order('0000000001234'));
    }

    public function testRequestIsPricedInTheOrderCurrencyAndPointsBackToTheStore(): void
    {
        $order = $this->order('000000123', 125.456, 'cad', 'Lux "Store" ✨', false);

        $request = $this->builder->build($order, 'STG000000123');

        $this->assertSame(['terminalType' => 'WEB'], $request['env']);
        $this->assertSame('STG000000123', $request['merchantTradeNo']);
        $this->assertSame(125.46, $request['fiatAmount']);
        $this->assertSame('CAD', $request['fiatCurrency']);
        $this->assertSame('Order 000000123 - Lux Store', $request['description']);
        $this->assertSame('01', $request['goodsDetails'][0]['goodsType']);
        $this->assertSame('Z000', $request['goodsDetails'][0]['goodsCategory']);
        $this->assertSame('STG000000123', $request['goodsDetails'][0]['referenceGoodsId']);
        $this->assertSame('Joust Duffle Bag x 2, Hoodie x 1', $request['goodsDetails'][0]['goodsDetail']);
        $this->assertSame('https://shop.test/joepham_binancepay/checkout/complete/?store=2', $request['returnUrl']);
        $this->assertSame('https://shop.test/joepham_binancepay/checkout/cancel/?store=2', $request['cancelUrl']);
        $this->assertSame('https://shop.test/joepham_binancepay/webhook/?store=2', $request['webhookUrl']);
        $this->assertSame('42', $request['passThroughInfo']);
    }

    public function testVirtualOrderIsSentAsVirtualGoods(): void
    {
        $request = $this->builder->build($this->order('000000124', 10.0, 'USD', 'Shop', true), 'X1');

        $this->assertSame('02', $request['goodsDetails'][0]['goodsType']);
    }

    /**
     * @param string $incrementId
     * @param float $grandTotal
     * @param string $currency
     * @param string $storeName
     * @param bool $virtual
     * @return Order|MockObject
     */
    private function order(
        string $incrementId,
        float $grandTotal = 10.0,
        string $currency = 'USD',
        string $storeName = 'Shop',
        bool $virtual = false
    ) {
        $store = $this->createMock(Store::class);
        $store->method('getFrontendName')->willReturn($storeName);
        $items = [];
        foreach ([['Joust Duffle Bag', 2.0], ['Hoodie', 1.0]] as [$name, $qty]) {
            $item = $this->createMock(Item::class);
            $item->method('getName')->willReturn($name);
            $item->method('getQtyOrdered')->willReturn($qty);
            $items[] = $item;
        }

        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStoreId')->willReturn(2);
        $order->method('getEntityId')->willReturn(42);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn($currency);
        $order->method('getIsVirtual')->willReturn($virtual);
        $order->method('getStore')->willReturn($store);
        $order->method('getAllVisibleItems')->willReturn($items);

        return $order;
    }
}
