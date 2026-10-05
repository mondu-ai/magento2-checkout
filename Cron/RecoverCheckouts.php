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

    /**
     * A checkout that failed this often is given up on: whatever stops it (stock, shipping
     * method, a refused confirmation) is not going to go away by itself.
     */
    private const MAX_ATTEMPTS = 12;

    /**
     * Delay after the first failure, doubled after each further one up to MAX_RETRY_DELAY_MINUTES.
     * Twelve attempts then span about the two days of MAX_AGE_MINUTES.
     */
    private const FIRST_RETRY_DELAY_MINUTES = 10;

    private const MAX_RETRY_DELAY_MINUTES = 6 * 60;

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
            $order = null;
            try {
                $order = $this->checkoutRecovery->placeOrder($checkout['order_uuid'], CheckoutRecovery::SOURCE_CRON);
            } catch (Exception $e) {
                $this->monduFileLogger->error('RecoverCheckouts: could not place the order', [
                    'order_uuid' => $checkout['order_uuid'],
                    'error' => $e->getMessage(),
                ]);
            }

            if ($order === null) {
                $this->scheduleRetry($checkout['order_uuid'], (int) $checkout['attempts'] + 1);
            }
        }

        $this->pendingCheckout->deleteOlderThan(self::RETENTION_DAYS);

        return $this;
    }

    /**
     * Backs off a checkout that is still open after this run, or gives up on it.
     *
     * A link the run closed (order placed, Mondu order declined, ...) is left as it is.
     *
     * @param string $orderUuid
     * @param int $attempts Failed attempts so far, this one included
     * @return void
     */
    private function scheduleRetry(string $orderUuid, int $attempts): void
    {
        $link = $this->pendingCheckout->getByOrderUuid($orderUuid);
        if (!$link || $link['processed_at'] !== null) {
            return;
        }

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->monduFileLogger->error('RecoverCheckouts: giving up on the checkout', [
                'order_uuid' => $orderUuid,
                'attempts' => $attempts,
            ]);
            $this->pendingCheckout->markProcessed($orderUuid);
            return;
        }

        $delay = min(self::FIRST_RETRY_DELAY_MINUTES * 2 ** ($attempts - 1), self::MAX_RETRY_DELAY_MINUTES);
        $this->pendingCheckout->scheduleRetry($orderUuid, $attempts, (int) $delay);
    }
}
