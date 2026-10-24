<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 *
 * Prints an order's state as JSON for the end-to-end tests: php order-state.php <magento root> <increment id>
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Sales\Model\Order;

require rtrim($argv[1], '/') . '/app/bootstrap.php';

$order = Bootstrap::create(BP, $_SERVER)->getObjectManager()->create(Order::class)->loadByIncrementId($argv[2]);
if (!$order->getId()) {
    echo json_encode(['found' => false]);
    exit(1);
}

$comments = [];
foreach ($order->getStatusHistoryCollection() as $history) {
    $comments[] = (string)$history->getComment();
}
$invoices = [];
foreach ($order->getInvoiceCollection() as $invoice) {
    $invoices[] = ['id' => (int)$invoice->getId(), 'state' => (int)$invoice->getState(), 'total' => $invoice->getGrandTotal()];
}

echo json_encode([
    'found' => true,
    'id' => (int)$order->getId(),
    'state' => $order->getState(),
    'status' => $order->getStatus(),
    'grand_total' => $order->getGrandTotal(),
    'currency' => $order->getOrderCurrencyCode(),
    'total_paid' => $order->getTotalPaid(),
    'total_refunded' => $order->getTotalRefunded(),
    'email_sent' => (bool)$order->getEmailSent(),
    'invoices' => $invoices,
    'creditmemos' => (int)$order->getCreditmemosCollection()->getSize(),
    'payment' => [
        'method' => $order->getPayment()->getMethod(),
        'last_trans_id' => $order->getPayment()->getLastTransId(),
        'info' => $order->getPayment()->getAdditionalInformation(),
    ],
    'comments' => $comments,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
