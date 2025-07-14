<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod as PaymentMethodHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CancelOrder extends MonduObserver
{
    protected string $name = 'CancelOrder';

    /**
     * @param ContextHelper $contextHelper
     * @param MonduFileLogger $monduFileLogger
     * @param PaymentMethodHelper $paymentMethodHelper
     * @param ManagerInterface $messageManager
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        ContextHelper $contextHelper,
        MonduFileLogger $monduFileLogger,
        PaymentMethodHelper $paymentMethodHelper,
        private readonly ManagerInterface $messageManager,
        private readonly RequestFactory $requestFactory,
    ) {
        parent::__construct($contextHelper, $monduFileLogger, $paymentMethodHelper);
    }

    /**
     * Execute.
     *
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function _execute(Observer $observer): void
    {
        /** @var OrderInterface $order */
        $order = $observer->getEvent()->getOrder();
        $orderIncrementId = $order->getIncrementId();
        $monduId = $order->getMonduReferenceId();

        try {
            if ($order->getRelationChildId()) {
                return;
            }

            $this->monduFileLogger->info('Trying to cancel Order ' . $orderIncrementId);

            $cancelData = $this->requestFactory->create(RequestFactory::CANCEL)
                ->process(['orderUid' => $monduId]);
            if (!$cancelData) {
                $this->messageManager->addErrorMessage(
                    'Mondu: Unexpected error: Order could not be found,'
                    . ' please contact Mondu Support to resolve this issue.'
                );
                return;
            }

            $order->addCommentToStatusHistory(
                __('Mondu: The order with the id %1 was successfully canceled.', $monduId)
            );
            $order->save();
            $this->monduFileLogger->info('Cancelled order ', ['orderNumber' => $orderIncrementId]);
        } catch (Exception $error) {
            $this->monduFileLogger->info(
                'Failed to cancel Order ' . $orderIncrementId,
                ['e' => $error->getMessage()]
            );
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
