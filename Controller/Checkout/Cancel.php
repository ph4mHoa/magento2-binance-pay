<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Controller\Checkout;

use JoePham\BinancePay\Model\CheckoutFlow;
use JoePham\BinancePay\Model\Config;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;

/**
 * Binance cancelUrl: the shopper left the Binance checkout without paying.
 */
class Cancel implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param CheckoutFlow $checkoutFlow
     * @param RedirectFactory $redirectFactory
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CheckoutFlow $checkoutFlow,
        private readonly RedirectFactory $redirectFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(): RedirectResult
    {
        $redirect = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order->getId() || $order->getPayment()?->getMethod() !== Config::METHOD_CODE) {
            return $redirect->setPath(CheckoutFlow::CART_PATH);
        }

        return $redirect->setPath($this->checkoutFlow->giveUp((int)$order->getId()));
    }
}
