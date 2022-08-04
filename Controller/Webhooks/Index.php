<?php
namespace Mondu\Mondu\Controller\Webhooks;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionInterface;
//use Magento\Framework\App\CsrfAwareActionInterface;
//use Balancepay\Balancepay\Model\Request\Factory as RequestFactory;
//use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Index extends Action implements ActionInterface {
    private $_jsonFactory;
//    private $_requestFactory;
    private $_json;
    private $_orderFactory;
    private $_monduLogger;
    private $_monduConfig;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
//        RequestFactory $requestFactory,
        Json $json,
        OrderFactory $orderFactory,
        Log $logger,
        ConfigProvider $monduConfig
    ) {
        parent::__construct($context);
        $this->_jsonFactory = $jsonFactory;
//        $this->_requestFactory = $requestFactory;
        $this->_json = $json;
        $this->_orderFactory = $orderFactory;
        $this->_monduLogger = $logger;
        $this->_monduConfig = $monduConfig;
    }

    public function execute()
    {
        $resBody = [];
        $resStatus = \Magento\Framework\Webapi\Response::HTTP_OK;

        try {
            $content = $this->getRequest()->getContent();
            $headers = $this->getRequest()->getHeaders()->toArray();
            $signature = hash_hmac('sha256',$content, $this->_monduConfig->getWebhookSecret());
            if($signature !== @$headers['X-Mondu-Signature']) {
                throw new \Exception('Signature mismatch');
            }
            $params = $this->_json->unserialize($content);

            $topic = $params['topic'];

            switch ($topic) {
                case 'order/confirmed':
                    [$resBody, $resStatus] = $this->handleConfirmed($params);
                    break;
                case 'order/pending':
                    [$resBody, $resStatus] = $this->handlePending($params);
                    break;
                case 'order/canceled':
                case 'order/declined':
                    [$resBody, $resStatus] = $this->handleDeclinedOrCanceled($params);
                    break;
                case 'invoice/paid':
                    [$resBody, $resStatus] = $this->handleInvoicePaid($params);
                    break;
                default:
                    throw new \Exception('Unregistered topic');
            }
        } catch (\Exception $e) {
            $resBody = [
                'error' => 1,
                'message' => $e->getMessage()
            ];
            $resStatus = 400;
        }

        return $this->_jsonFactory->create()
            ->setHttpResponseCode($resStatus)
            ->setData($resBody);
    }

    /**
     * @throws \Exception
     */
    public function handlePending($params): array
    {
        $externalReferenceId = @$params['external_reference_id'];

        $monduId = @$params['order_uuid'];
        $order = $this->_orderFactory->create()->loadByIncrementId($externalReferenceId);

        if(!$externalReferenceId || !$monduId) {
            throw new \Exception('Required params missing');
        }

        if(!$order || !$order->getIncrementId()) {
            return [['message' => 'Not Found', 'error' => 1], 404];
        }

        $this->_monduLogger->updateLogMonduData($monduId, $params['order_state']);
        $order->setStatus(Order::STATE_PROCESSING)->save();

        return [['message' => 'ok', 'error' => 0], 200];
    }
    /**
     * @throws \Exception
     */
    public function handleConfirmed($params): array
    {
        $viban = @$params['bank_account']['iban'];
        $monduId = @$params['order_uuid'];
        $externalReferenceId = @$params['external_reference_id'];
        $order = $this->_orderFactory->create()->loadByIncrementId($externalReferenceId);

        if(!$viban || !$externalReferenceId) {
            throw new \Exception('Required params missing');
        }
        $this->_monduLogger->updateLogMonduData($monduId, $params['order_state'], $viban);
        $order->setStatus(Order::STATE_PROCESSING)->save();

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * @throws \Exception
     */
    public function handleDeclinedOrCanceled($params): array
    {
        $monduId = @$params['order_uuid'];
        $externalReferenceId = @$params['external_reference_id'];
        $orderState = @$params['order_state'];
        $order = $this->_orderFactory->create()->loadByIncrementId($externalReferenceId);

        if(!$monduId || !$externalReferenceId || !$orderState) {
            throw new \Exception('Required params missing');
        }
        if (!empty($order->getData)) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }
        if ($orderState === 'canceled') {
            $order->setStatus(Order::STATE_CANCELED)->save();
        } elseif ($orderState === 'declined') {
            if(@$params['reason'] === 'buyer_fraud') {
                $order->setStatus(Order::STATUS_FRAUD)->save();
            } else {
                $order->setStatus(Order::STATE_CANCELED)->save();
            }
        }

        $this->_monduLogger->updateLogMonduData($monduId, $params['order_state']);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * @throws \Exception
     */
    public function handleInvoicePaid($params): array {
        $invoiceUid = @$params['invoice_uuid'];
        $externalReferenceId = @$params['external_reference_id'];

        if(!$invoiceUid || !$externalReferenceId) {
            throw new \Exception('Required params missing');
        }
        //TODO implement
        return ['message' => 'ok', 'error' => 0, 200];
    }

//    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
//    {
//        return null;
//    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
