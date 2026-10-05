<?php

declare(strict_types=1);

namespace Mondu\Mondu\Service;

use Exception;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Config\ScopeInterface as ConfigScope;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\Log as MonduTransactions;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Checkout\GuestQuote;
use Mondu\Mondu\Model\Checkout\OrderUuidContext;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\ResourceModel\PendingCheckout;

/**
 * Places and confirms the Magento order for an authorized Mondu order when the buyer's browser
 * never came back to the success page (tab closed during the redirect, connection lost, ...).
 */
class CheckoutRecovery
{
    public const SOURCE_WEBHOOK = 'webhook';
    public const SOURCE_CRON = 'cron';

    /**
     * Mondu states in which the order is waiting for the merchant's confirmation.
     */
    private const PLACEABLE_STATES = [OrderHelper::AUTHORIZED, 'pending'];

    /**
     * Mondu states after which the order can no longer be placed from the quote.
     *
     * Only these close the link. Anything else, a missing state above all, is what an
     * HTTP error answers with, and the cron is there to retry exactly those.
     */
    private const TERMINAL_STATES = [
        OrderHelper::DECLINED,
        OrderHelper::CANCELED,
        MonduTransactions::MONDU_STATE_CONFIRMED,
        MonduTransactions::MONDU_STATE_PARTIALLY_SHIPPED,
        MonduTransactions::MONDU_STATE_PARTIALLY_COMPLETE,
        MonduTransactions::MONDU_STATE_SHIPPED,
        MonduTransactions::MONDU_STATE_COMPLETE,
    ];

    /**
     * Mondu state of an order the buyer has not finished yet.
     */
    private const DRAFT_STATE = 'draft';

    /**
     * After this long a draft counts as an abandoned checkout and the cron stops polling it.
     */
    private const DRAFT_ABANDONED_AFTER_MINUTES = 180;

    private const LOCK_PREFIX = 'mondu_place_order_';
    private const LOCK_TIMEOUT = 30;

    /**
     * @param AppState $appState
     * @param CartManagementInterface $quoteManagement
     * @param CartRepositoryInterface $cartRepository
     * @param ConfigScope $configScope
     * @param GuestQuote $guestQuote
     * @param LockManagerInterface $lockManager
     * @param MonduFileLogger $monduFileLogger
     * @param MonduTransactions $monduTransactions
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderSender $orderSender
     * @param OrderUuidContext $orderUuidContext
     * @param PendingCheckout $pendingCheckout
     * @param RequestFactory $requestFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly AppState $appState,
        private readonly CartManagementInterface $quoteManagement,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ConfigScope $configScope,
        private readonly GuestQuote $guestQuote,
        private readonly LockManagerInterface $lockManager,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly MonduTransactions $monduTransactions,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderSender $orderSender,
        private readonly OrderUuidContext $orderUuidContext,
        private readonly PendingCheckout $pendingCheckout,
        private readonly RequestFactory $requestFactory,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * Serializes order placement for one Mondu order between the buyer's return and the backup paths.
     *
     * @param string $orderUuid
     * @return bool
     */
    public function lock(string $orderUuid): bool
    {
        return $this->lockManager->lock(self::LOCK_PREFIX . $orderUuid, self::LOCK_TIMEOUT);
    }

    /**
     * Releases the lock taken by lock().
     *
     * @param string $orderUuid
     * @return void
     */
    public function unlock(string $orderUuid): void
    {
        $this->lockManager->unlock(self::LOCK_PREFIX . $orderUuid);
    }

    /**
     * Places the Magento order for the Mondu order unless it already exists.
     *
     * Returns the Magento order, or null when there is nothing to place (yet).
     *
     * @param string $orderUuid
     * @param string $source
     * @throws LocalizedException
     * @return OrderInterface|null
     */
    public function placeOrder(string $orderUuid, string $source): ?OrderInterface
    {
        if (!$this->lock($orderUuid)) {
            throw new LocalizedException(__('Mondu: order %1 is being placed by another process', $orderUuid));
        }

        try {
            return $this->placeOrderLocked($orderUuid, $source);
        } finally {
            $this->unlock($orderUuid);
        }
    }

    /**
     * Places the order; the caller holds the lock.
     *
     * @param string $orderUuid
     * @param string $source
     * @throws LocalizedException
     * @return OrderInterface|null
     */
    private function placeOrderLocked(string $orderUuid, string $source): ?OrderInterface
    {
        $transaction = $this->monduTransactions->getTransactionByOrderUid($orderUuid);
        if (!empty($transaction['order_id'])) {
            $this->pendingCheckout->markProcessed($orderUuid);
            return $this->orderRepository->get($transaction['order_id']);
        }

        $link = $this->pendingCheckout->getByOrderUuid($orderUuid);
        if (!$link) {
            return null;
        }

        $storeId = $link['store_id'] !== null ? (int) $link['store_id'] : null;
        $context = ['order_uuid' => $orderUuid, 'quote_id' => $link['quote_id'], 'source' => $source];

        $monduOrder = $this->requestFactory->create(RequestFactory::TRANSACTION_CONFIRM_METHOD, $storeId)
            ->setValidate(false)
            ->process(['orderUid' => $orderUuid]);
        $state = $monduOrder['order']['state'] ?? null;

        if ($state === self::DRAFT_STATE) {
            $ageMinutes = (time() - strtotime($link['created_at'] . ' UTC')) / 60;
            if ($ageMinutes > self::DRAFT_ABANDONED_AFTER_MINUTES) {
                $this->pendingCheckout->markProcessed($orderUuid);
            }
            return null;
        }

        if (in_array($state, self::TERMINAL_STATES, true)) {
            $this->monduFileLogger->info('CheckoutRecovery: Mondu order is not awaiting confirmation', $context + [
                'mondu_state' => $state,
            ]);
            $this->pendingCheckout->markProcessed($orderUuid);
            return null;
        }

        if (!in_array($state, self::PLACEABLE_STATES, true)) {
            // With validation off, an HTTP error from Mondu (5xx, 429, 401) comes back as a body
            // without an order. Leave the link open so the next run asks again.
            $this->monduFileLogger->warning('CheckoutRecovery: no usable Mondu order state, will retry', $context + [
                'mondu_state' => $state,
                'response' => $monduOrder,
            ]);
            return null;
        }

        $quote = $this->getPlaceableQuote((int) $link['quote_id'], $monduOrder['order'], $context);
        if (!$quote) {
            $this->pendingCheckout->markProcessed($orderUuid);
            return null;
        }

        if ($storeId !== null) {
            $this->storeManager->setCurrentStore($storeId);
        }

        // There is no session here: a quote without a customer is a guest checkout.
        if (!$quote->getCustomerId()) {
            $this->guestQuote->prepare($quote);
        }

        $order = $this->submitQuote($quote, $orderUuid);
        $this->pendingCheckout->markProcessed($orderUuid);

        if (!$order->getData('mondu_reference_id')) {
            // The quote is used up now, so nothing will retry: this needs a human.
            $this->monduFileLogger->error('CheckoutRecovery: order placed but not linked to Mondu', $context + [
                'order_increment_id' => $order->getIncrementId(),
            ]);
        }

        $order->addCommentToStatusHistory(__('Mondu: order placed by the %1', $source));
        $this->orderRepository->save($order);

        try {
            if (!$order->getEmailSent()) {
                $this->orderSender->send($order);
            }
        } catch (Exception $e) {
            $this->monduFileLogger->error('CheckoutRecovery: order email failed', $context + [
                'error' => $e->getMessage(),
            ]);
        }

        $this->monduFileLogger->info('CheckoutRecovery: order placed', $context + [
            'order_increment_id' => $order->getIncrementId(),
            'mondu_state' => $state,
        ]);

        return $order;
    }

    /**
     * Submits the quote as a storefront order, so the storefront observers confirm it with Mondu.
     *
     * @param CartInterface $quote
     * @param string $orderUuid
     * @throws Exception
     * @return OrderInterface
     */
    private function submitQuote(CartInterface $quote, string $orderUuid): OrderInterface
    {
        $submit = function () use ($quote, $orderUuid): OrderInterface {
            $this->orderUuidContext->setOrderUuid($orderUuid);
            try {
                $quote->collectTotals();
                return $this->quoteManagement->submit($quote);
            } finally {
                $this->orderUuidContext->setOrderUuid(null);
            }
        };

        if ($this->appState->getAreaCode() === Area::AREA_FRONTEND) {
            return $submit();
        }

        // emulateAreaCode() alone keeps the cron's event and plugin configuration
        $previousScope = $this->configScope->getCurrentScope();
        $this->configScope->setCurrentScope(Area::AREA_FRONTEND);
        try {
            return $this->appState->emulateAreaCode(Area::AREA_FRONTEND, $submit);
        } finally {
            $this->configScope->setCurrentScope($previousScope);
        }
    }

    /**
     * Returns the quote if it can still be turned into the order the buyer paid for.
     *
     * @param int $quoteId
     * @param array $monduOrder
     * @param array $context
     * @return CartInterface|null
     */
    private function getPlaceableQuote(int $quoteId, array $monduOrder, array $context): ?CartInterface
    {
        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException $e) {
            $this->monduFileLogger->error('CheckoutRecovery: quote not found', $context);
            return null;
        }

        if (!$quote->getIsActive()) {
            $this->monduFileLogger->error('CheckoutRecovery: quote is no longer active', $context);
            return null;
        }

        if (!in_array($quote->getPayment()->getMethod(), PaymentMethod::PAYMENTS, true)) {
            $this->monduFileLogger->error('CheckoutRecovery: quote no longer uses a Mondu payment method', $context + [
                'payment_method' => $quote->getPayment()->getMethod(),
            ]);
            return null;
        }

        $quote->collectTotals();
        $quoteCents = (int) round((float) $quote->getBaseGrandTotal() * 100);
        $monduCents = isset($monduOrder['real_price_cents']) ? (int) $monduOrder['real_price_cents'] : null;

        if ($monduCents !== null && $monduCents !== $quoteCents) {
            $this->monduFileLogger->error('CheckoutRecovery: quote total differs from the Mondu order', $context + [
                'quote_cents' => $quoteCents,
                'mondu_cents' => $monduCents,
            ]);
            return null;
        }

        return $quote;
    }
}
