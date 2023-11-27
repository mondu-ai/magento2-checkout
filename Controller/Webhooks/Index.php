<?php
namespace Mondu\Mondu\Controller\Webhooks;

use Exception;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\OrderFactory;
use Mondu\Mondu\Helpers\Log;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Index extends Action implements ActionInterface
{
    /**
     * @var JsonFactory
     */
    private $_jsonFactory;

    /**
     * @var Json
     */
    private $_json;

    /**
     * @var OrderFactory
     */
    private $_orderFactory;

    /**
     * @var Log
     */
    private $_monduLogger;

    /**
     * @var ConfigProvider
     */
    private $_monduConfig;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Json $json
     * @param OrderFactory $orderFactory
     * @param Log $logger
     * @param ConfigProvider $monduConfig
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Json $json,
        OrderFactory $orderFactory,
        Log $logger,
        ConfigProvider $monduConfig
    ) {
        parent::__construct($context);
        $this->_jsonFactory = $jsonFactory;
        $this->_json = $json;
        $this->_orderFactory = $orderFactory;
        $this->_monduLogger = $logger;
        $this->_monduConfig = $monduConfig;
    }

    /**
     * Execute
     *
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $resBody = [];
        $resStatus = \Magento\Framework\Webapi\Response::HTTP_OK;

        try {
            $content = $this->getRequest()->getContent();

            $headers = $this->getRequest()->getHeaders()->toArray();
            $signature = hash_hmac('sha256', $content, $this->_monduConfig->getWebhookSecret());
            if ($signature !== ($headers['X-Mondu-Signature'] ?? null)) {
                throw new AuthorizationException(__('Signature mismatch'));
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
                case 'order/declined':
                    [$resBody, $resStatus] = $this->handleDeclinedOrCanceled($params);
                    break;
                default:
                    throw new AuthorizationException(__('Unregistered topic'));
            }
        } catch (AuthorizationException|Exception $e) {
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
     * HandlePending
     *
     * @param array|null $params
     * @return array
     * @throws Exception
     */
    public function handlePending($params): array
    {
        $externalReferenceId = $params['external_reference_id'] ?? null;

        $monduId = $params['order_uuid'] ?? null;
        $order = $this->_orderFactory->create()->loadByIncrementId($externalReferenceId);

        if (!$externalReferenceId || !$monduId) {
            throw new Exception('Required params missing');
        }

        if (empty($order->getData())) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }
        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus(Order::STATE_PAYMENT_REVIEW);
        $order->addStatusHistoryComment(
            __('Mondu: Order Status changed to Payment Review by a webhook')
        );
        $order->save();
        $this->_monduLogger->updateLogMonduData($monduId, $params['order_state']);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * HandleConfirmed
     *
     * @param array|null $params
     * @return array
     * @throws Exception
     */
    public function handleConfirmed($params): array
    {
        $viban = $params['bank_account']['iban'] ?? null;
        $monduId = $params['order_uuid'] ?? null;
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $order = $this->_orderFactory->create()->loadByIncrementId($externalReferenceId);

        if (!$viban || !$externalReferenceId) {
            throw new Exception('Required params missing');
        }

        if (empty($order->getData())) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addStatusHistoryComment(
            __('Mondu: Order Status changed to Processing by a webhook')
        );
        $order->save();
        $this->_monduLogger->updateLogMonduData($monduId, $params['order_state'], $viban);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * HandleDeclinedOrCanceled
     *
     * @param array|null $params
     * @return array
     * @throws Exception
     */
    public function handleDeclinedOrCanceled($params): array
    {
        $monduId = $params['order_uuid'] ?? null;
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $orderState = $params['order_state'] ?? null;
        $order = $this->_orderFactory->create()->loadByIncrementId($externalReferenceId);

        if (!$monduId || !$externalReferenceId || !$orderState) {
            throw new Exception('Required params missing');
        }

        if (empty($order->getData())) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $order->addStatusHistoryComment(
            __('Mondu: Order has been declined')
        );

        if ($orderState === 'canceled') {
            $order->setStatus(Order::STATE_CANCELED)->save();
        } elseif ($orderState === 'declined') {
            if (isset($params['reason']) && $params['reason'] === 'buyer_fraud') {
                $order->setStatus(Order::STATUS_FRAUD)->save();
            } else {
                $order->setStatus(Order::STATE_CANCELED)->save();
            }
        }

        $this->_monduLogger->updateLogMonduData($monduId, $params['order_state']);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * ValidateForCsrf
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }
}
