<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Where the shopper goes after Binance: the success page, or back to the cart with the quote restored.
 */
class CheckoutFlow
{
    public const SUCCESS_PATH = 'checkout/onepage/success';
    public const CART_PATH = 'checkout/cart';

    /**
     * @param OrderSync $orderSync
     * @param CheckoutSession $checkoutSession
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OrderSync $orderSync,
        private readonly CheckoutSession $checkoutSession,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The shopper came back from a successful payment: settle the order now rather than wait for the webhook.
     *
     * @param int $orderId
     * @return string route path to redirect to
     */
    public function finish(int $orderId): string
    {
        try {
            $order = $this->orderSync->sync($orderId);
        } catch (\Exception $e) {
            $this->logger->error('Binance Pay status check failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->addWaitingNotice();
            return self::SUCCESS_PATH;
        }

        return $this->routeFor($order);
    }

    /**
     * The payment cannot go ahead: close it on Binance (unless it was paid after all) and restore the cart.
     *
     * @param int $orderId
     * @return string route path to redirect to
     */
    public function giveUp(int $orderId): string
    {
        try {
            $order = $this->orderSync->abandon($orderId);
        } catch (\Exception $e) {
            // Binance refuses to close an order that is being paid right now; keep it and let the webhook decide.
            $this->logger->warning('Binance Pay order not closed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            $this->messageManager->addNoticeMessage(
                __('Your Binance Pay payment is still being processed. You will get an email once it is confirmed.')
            );
            return self::SUCCESS_PATH;
        }

        return $this->routeFor($order);
    }

    /**
     * Success page unless the order ended up cancelled.
     *
     * @param Order $order
     * @return string
     */
    private function routeFor(Order $order): string
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(
                __('The Binance Pay payment was not completed. Your cart has been restored, please try again.')
            );
            return self::CART_PATH;
        }
        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            $this->addWaitingNotice();
        }

        return self::SUCCESS_PATH;
    }

    /**
     * Tell the shopper the payment is not confirmed yet.
     *
     * @return void
     */
    private function addWaitingNotice(): void
    {
        $this->messageManager->addNoticeMessage(
            __('We are waiting for Binance Pay to confirm your payment. You will get an email once it is confirmed.')
        );
    }
}
