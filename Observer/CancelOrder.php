<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Exception;
use \Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CancelOrder implements ObserverInterface
{
    const CODE = 'mondu';

    private $_requestFactory;

    public function __construct(RequestFactory $requestFactory)
    {
        $this->_requestFactory = $requestFactory;
    }

    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment()->getMethodInstance();
        $monduId = $order->getData('mondu_reference_id');

        if ($payment->getCode() != self::CODE && $payment->getMethod() != self::CODE) {
            return;
        }

        try {
            if(!$order->getRelationChildId()) {
                $cancelData = $this->_requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);
                $order->addStatusHistoryComment(__('Mondu:  The transaction with the id %1 was successfully canceled.', $monduId));
                $order->save();
            }
        } catch (Exception $error) {
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
