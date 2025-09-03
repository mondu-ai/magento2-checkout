<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Webhooks;

use Exception;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\AuthorizationException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Index implements ActionInterface
{
    /**
     * @param ConfigProvider $monduConfig
     * @param JsonFactory $resultJson
     * @param MonduLogHelper $monduLogHelper
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param RequestInterface $request
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly ConfigProvider $monduConfig,
        private readonly JsonFactory $resultJson,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly RequestInterface $request,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Dispatches incoming webhook requests and processes them based on Mondu topic.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        try {
            $content = $this->request->getContent();
            $headers = $this->request->getHeaders()->toArray();
            $params = $this->serializer->unserialize($content);
            $this->validateWebhookSignature($content, $headers, $params);

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
        } catch (Exception $e) {
            $resBody = ['error' => 1, 'message' => $e->getMessage()];
            $resStatus = 400;
        }

        return $this->resultJson->create()->setHttpResponseCode($resStatus)->setData($resBody);
    }

    /**
     * Processes the 'order/pending' topic and moves order to payment review state.
     *
     * @param array|null $params
     * @throws Exception
     * @return array
     */
    public function handlePending(?array $params): array
    {
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $monduId = $params['order_uuid'] ?? null;

        if (!$externalReferenceId || !$monduId) {
            throw new Exception('Required params missing');
        }

        try {
            $order = $this->getOrderByIncrementId($externalReferenceId);
        } catch (AuthorizationException $e) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $order->setState(Order::STATE_PAYMENT_REVIEW);
        $order->setStatus(Order::STATE_PAYMENT_REVIEW);
        $order->addCommentToStatusHistory(
            __('Mondu: Order Status changed to Payment Review by a webhook')
        );
        $this->orderRepository->save($order);
        $this->monduLogHelper->updateLogMonduData($monduId, $params['order_state']);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * Processes the 'order/confirmed' topic and updates the order to processing state.
     *
     * @param array|null $params
     * @throws Exception
     * @return array
     */
    public function handleConfirmed(?array $params): array
    {
        $viban = $params['bank_account']['iban'] ?? null;
        $monduId = $params['order_uuid'] ?? null;
        $externalReferenceId = $params['external_reference_id'] ?? null;

        if (!$viban || !$externalReferenceId) {
            throw new Exception('Required params missing');
        }

        try {
            /** @var OrderInterface $order */
            $order = $this->getOrderByIncrementId($externalReferenceId);
        } catch (AuthorizationException $e) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $order->setState(Order::STATE_PROCESSING);
        $order->setStatus(Order::STATE_PROCESSING);
        $order->addCommentToStatusHistory(
            __('Mondu: Order Status changed to Processing by a webhook')
        );
        $this->orderRepository->save($order);
        $this->monduLogHelper->updateLogMonduData($monduId, $params['order_state'], $viban);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * Processes the 'order/declined' or 'order/canceled' topic and cancels or flags the order as fraud.
     *
     * @param array|null $params
     * @throws Exception
     * @return array
     */
    public function handleDeclinedOrCanceled(?array $params): array
    {
        $monduId = $params['order_uuid'] ?? null;
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $orderState = $params['order_state'] ?? null;

        if (!$monduId || !$externalReferenceId || !$orderState) {
            throw new Exception('Required params missing');
        }

        try {
            $order = $this->getOrderByIncrementId($externalReferenceId);
        } catch (AuthorizationException $e) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $order->addCommentToStatusHistory(
            __('Mondu: Order has been declined')
        );

        if ($orderState === OrderHelper::CANCELED) {
            $order->setStatus(Order::STATE_CANCELED);
            $this->orderRepository->save($order);
        } elseif ($orderState === OrderHelper::DECLINED) {
            if (isset($params['reason']) && $params['reason'] === 'buyer_fraud') {
                $order->setStatus(Order::STATUS_FRAUD);
            } else {
                $order->setStatus(Order::STATE_CANCELED);
            }
            $this->orderRepository->save($order);
        }

        $this->monduLogHelper->updateLogMonduData($monduId, $params['order_state']);

        return [['message' => 'ok', 'error' => 0], 200];
    }

    /**
     * Finds order by increment ID using modern repository pattern.
     *
     * @param string $incrementId
     * @throws AuthorizationException
     * @return OrderInterface
     */
    private function getOrderByIncrementId(string $incrementId): OrderInterface
    {
        $filter = $this->filterBuilder
            ->setField('increment_id')
            ->setValue($incrementId)
            ->setConditionType('eq')
            ->create();

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilters([$filter])
            ->setPageSize(1)
            ->create();

        $orderList = $this->orderRepository->getList($searchCriteria);

        if ($orderList->getTotalCount() === 0) {
            throw new AuthorizationException(__('Order not found: %1', $incrementId));
        }

        $orders = $orderList->getItems();
        return reset($orders);
    }

    /**
     * Validates webhook signature with multistore support.
     *
     * @param string $content
     * @param array $headers
     * @param array $params
     * @throws AuthorizationException
     * @return void
     */
    private function validateWebhookSignature(string $content, array $headers, array $params): void
    {
        $externalReferenceId = $params['external_reference_id'] ?? null;

        if (!$externalReferenceId) {
            throw new AuthorizationException(__('Missing external_reference_id for signature validation'));
        }

        $order = $this->getOrderByIncrementId($externalReferenceId);

        $this->monduConfig->setContextCode((int) $order->getStoreId());
        $expectedSignature = hash_hmac('sha256', $content, $this->monduConfig->getWebhookSecret());
        $receivedSignature = $headers['X-Mondu-Signature'] ?? null;

        if ($expectedSignature !== $receivedSignature) {
            throw new AuthorizationException(__('Signature mismatch'));
        }
    }

    /**
     * Skips CSRF validation for webhook endpoint.
     *
     * @param RequestInterface $request
     * @return bool
     */
    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }
}
