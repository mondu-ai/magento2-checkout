<?php
namespace Mondu\Mondu\Observer;

use Error;
use Exception;
use \Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class UpdateOrder implements \Magento\Framework\Event\ObserverInterface
{
    const CODE = 'mondu';

    private $_checkoutSession;
    private $_requestFactory;
    protected $_monduLogger;

    public function __construct(CheckoutSession $checkoutSession, RequestFactory $requestFactory, \Magento\Framework\App\RequestInterface $request, \Mondu\Mondu\Helpers\Log $logger)
    {
        $this->_checkoutSession = $checkoutSession;
        $this->_requestFactory = $requestFactory;
        $this->_request = $request;
        $this->_monduLogger = $logger;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $creditMemo = $observer->getEvent()->getCreditmemo();

        $order = $creditMemo->getOrder();
        $payment = $order->getPayment();
        $monduId = $order->getData('mondu_reference_id');

        if ($payment->getCode() != self::CODE && $payment->getMethod() != self::CODE) {
            return;
        }
        try {
            if ($order->canCreditmemo() || $order->canInvoice()) {
                $requestParams = $this->_request->getParams();
                if(@$requestParams['creditmemo']['creditmemo_mondu_id']) {
                    $grossAmountCents = $creditMemo->getGrandTotal() * 100;
                    $data = [
                        'invoice_uid' => $requestParams['creditmemo']['creditmemo_mondu_id'],
                        'gross_amount_cents' => $grossAmountCents,
                        'external_reference_id' => $creditMemo->getIncrementId()
                    ];

                    $memoData = $this->_requestFactory->create(RequestFactory::MEMO)
                        ->process($data);
                    $this->_monduLogger->syncOrder($monduId);
                } else {
                    throw new LocalizedException(__('You cant partially refund order before Shipment'));
                }

                return;
            } else {
                $cancelData = $this->_requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if(@$cancelData['errors']) {
                    throw new LocalizedException(__('Unexpected error'));
                }

                $this->_monduLogger->updateLogMonduData($monduId, $cancelData['order']['state']);

                $order->addStatusHistoryComment(__('Mondu:  The transaction with the id %1 was successfully canceled.', $monduId));
                $order->save();
            }

        } catch (Exception $error) {
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
