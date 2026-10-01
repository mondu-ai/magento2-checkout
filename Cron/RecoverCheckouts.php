<?php

declare(strict_types=1);

namespace Mondu\Mondu\Cron;

use Exception;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\ResourceModel\PendingCheckout;
use Mondu\Mondu\Service\CheckoutRecovery;

class RecoverCheckouts
{
    /**
     * Gives the buyer time to come back from the hosted checkout before the cron steps in.
     */
    private const MIN_AGE_MINUTES = 15;

    /**
     * Stops retrying checkouts that could not be placed for this long.
     */
    private const MAX_AGE_MINUTES = 2 * 24 * 60;

    private const BATCH_SIZE = 50;

    private const RETENTION_DAYS = 30;

    /**
     * @param CheckoutRecovery $checkoutRecovery
     * @param MonduFileLogger $monduFileLogger
     * @param PendingCheckout $pendingCheckout
     */
    public function __construct(
        private readonly CheckoutRecovery $checkoutRecovery,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly PendingCheckout $pendingCheckout,
    ) {
    }

    /**
     * Places and confirms orders for authorized Mondu orders the buyer never came back from.
     *
     * @return $this
     */
    public function execute(): self
    {
        $checkouts = $this->pendingCheckout->getUnprocessed(
            self::MIN_AGE_MINUTES,
            self::MAX_AGE_MINUTES,
            self::BATCH_SIZE
        );

        foreach ($checkouts as $checkout) {
            try {
                $this->checkoutRecovery->placeOrder($checkout['order_uuid'], CheckoutRecovery::SOURCE_CRON);
            } catch (Exception $e) {
                $this->monduFileLogger->error('RecoverCheckouts: could not place the order', [
                    'order_uuid' => $checkout['order_uuid'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->pendingCheckout->deleteOlderThan(self::RETENTION_DAYS);

        return $this;
    }
}
