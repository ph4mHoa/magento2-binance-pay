<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Controller\Checkout;

use JoePham\BinancePay\Model\CheckoutFlow;
use JoePham\BinancePay\Model\Config;
use JoePham\BinancePay\Model\PaymentStarter;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Psr\Log\LoggerInterface;

/**
 * Right after "Place Order": create the Binance Pay order and send the shopper to the Binance checkout.
 */
class Redirect implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param PaymentStarter $paymentStarter
     * @param CheckoutFlow $checkoutFlow
     * @param RedirectFactory $redirectFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly PaymentStarter $paymentStarter,
        private readonly CheckoutFlow $checkoutFlow,
        private readonly RedirectFactory $redirectFactory,
        private readonly LoggerInterface $logger
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

        try {
            return $redirect->setUrl($this->paymentStarter->start((int)$order->getId()));
        } catch (\Exception $e) {
            $this->logger->error('Could not start the Binance Pay checkout', [
                'order' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }

        return $redirect->setPath($this->checkoutFlow->giveUp((int)$order->getId()));
    }
}
