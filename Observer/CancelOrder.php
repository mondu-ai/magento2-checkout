<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Exception;
use \Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CancelOrder implements ObserverInterface
{
    private $_requestFactory;

    private $monduFileLogger;

    private $paymentMethodHelper;
    private $messageManager;

    public function __construct(
        RequestFactory $requestFactory,
        MonduFileLogger $monduFileLogger,
        PaymentMethod $paymentMethodHelper,
        ManagerInterface $messageManager
    )
    {
        $this->_requestFactory = $requestFactory;
        $this->monduFileLogger = $monduFileLogger;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->messageManager = $messageManager;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();
        $monduId = $order->getData('mondu_reference_id');

        $this->monduFileLogger->info('Entered CancelOrder observer', ['orderNumber' => $order->getIncrementId()]);

        if (!$this->paymentMethodHelper->isMondu($payment)) {
            $this->monduFileLogger->info('Not a mondu order, skipping', ['orderNumber' => $order->getIncrementId()]);
            return;
        }

        try {
            if(!$order->getRelationChildId()) {
                $this->monduFileLogger->info('Trying to cancel Order '.$order->getIncrementId());

                $cancelData = $this->_requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if (!$cancelData) {
                    $this->messageManager->addErrorMessage('Mondu: Unexpected error: Order could not be found, please contact Mondu Support to resolve this issue.');
                    return;
                }

                $order->addStatusHistoryComment(__('Mondu:  The transaction with the id %1 was successfully canceled.', $monduId));
                $order->save();
                $this->monduFileLogger->info('Cancelled order ', ['orderNumber' => $order->getIncrementId()]);
            }
        } catch (Exception $error) {
            $this->monduFileLogger->info('Failed to cancel Order '.$order->getIncrementId(), ['e' => $error->getMessage()]);
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
