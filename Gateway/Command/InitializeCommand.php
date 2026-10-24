<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Gateway\Command;

use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Payment;

/**
 * Places the order as "pending payment" without contacting Binance.
 *
 * The Binance order is created once the Magento order exists, by the redirect controller, so a failure
 * there never leaves a Binance order pointing at an order number that was rolled back.
 */
class InitializeCommand implements CommandInterface
{
    /**
     * @param OrderConfig $orderConfig
     */
    public function __construct(
        private readonly OrderConfig $orderConfig
    ) {
    }

    /**
     * @inheritdoc
     */
    public function execute(array $commandSubject)
    {
        $stateObject = SubjectReader::readStateObject($commandSubject);
        /** @var Payment $payment */
        $payment = SubjectReader::readPayment($commandSubject)->getPayment();

        // The confirmation email goes out once the payment is received.
        $payment->getOrder()->setCanSendNewEmailFlag(false);

        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', $this->orderConfig->getStateDefaultStatus(Order::STATE_PENDING_PAYMENT));
        $stateObject->setData('is_notified', false);

        return null;
    }
}
