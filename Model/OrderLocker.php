<?php
/**
 * Copyright (c) Joe Pham. Licensed under the MIT License, see LICENSE.
 */
declare(strict_types=1);

namespace JoePham\BinancePay\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Serialises work on one order: the webhook, the return page and cron can all try to settle it at once.
 */
class OrderLocker
{
    private const LOCK_PREFIX = 'joepham_binancepay_order_';
    private const LOCK_TIMEOUT = 30;

    /**
     * @param LockManagerInterface $lockManager
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Run $callback while holding the order's lock and return its result.
     *
     * @param int $orderId
     * @param callable $callback
     * @return mixed
     * @throws LocalizedException when the lock cannot be taken in time
     */
    public function run(int $orderId, callable $callback)
    {
        $name = self::LOCK_PREFIX . $orderId;
        if (!$this->lockManager->lock($name, self::LOCK_TIMEOUT)) {
            throw new LocalizedException(__('Order %1 is being updated by another process.', $orderId));
        }
        try {
            return $callback();
        } finally {
            $this->lockManager->unlock($name);
        }
    }
}
