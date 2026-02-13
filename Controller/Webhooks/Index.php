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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Index implements ActionInterface
{
    /**
     * @param ConfigProvider $monduConfig
     * @param JsonFactory $resultJson
     * @param MonduLogHelper $monduLogHelper
     * @param MonduFileLogger $monduFileLogger
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param FilterBuilder $filterBuilder
     * @param RequestInterface $request
     * @param SerializerInterface $serializer
     * @param StoreManagerInterface $storeManager
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ConfigProvider $monduConfig,
        private readonly JsonFactory $resultJson,
        private readonly MonduLogHelper $monduLogHelper,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly FilterBuilder $filterBuilder,
        private readonly RequestInterface $request,
        private readonly SerializerInterface $serializer,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
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
            
            $this->monduFileLogger->info('Webhook received', [
                'topic' => $params['topic'] ?? 'unknown',
                'external_reference_id' => $params['external_reference_id'] ?? 'missing',
                'order_uuid' => $params['order_uuid'] ?? 'missing'
            ]);

            $validationResult = $this->validateWebhookSignatureAndFindOrder($content, $headers, $params);

            if ($validationResult === null) {
                $this->monduFileLogger->info('Webhook signature not matched for any website, ignoring request');
                return $this->resultJson->create()->setHttpResponseCode(200)->setData(['message' => 'ok', 'error' => 0]);
            }

            [$order, $storeId, $websiteId] = $validationResult;

            $topic = $params['topic'];

            switch ($topic) {
                case 'order/confirmed':
                    [$resBody, $resStatus] = $this->handleConfirmed($params, $order, $storeId);
                    break;
                case 'order/pending':
                    [$resBody, $resStatus] = $this->handlePending($params, $order, $storeId);
                    break;
                case 'order/declined':
                    [$resBody, $resStatus] = $this->handleDeclinedOrCanceled($params, $order, $storeId);
                    break;
                default:
                    throw new AuthorizationException(__('Unregistered topic'));
            }
        } catch (Exception $e) {
            $this->monduFileLogger->error('Webhook processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $resBody = ['error' => 1, 'message' => $e->getMessage()];
            $resStatus = 400;
        }

        return $this->resultJson->create()->setHttpResponseCode($resStatus)->setData($resBody);
    }

    /**
     * Processes the 'order/pending' topic and moves order to payment review state.
     *
     * @param array|null $params
     * @param OrderInterface|null $order
     * @param int|null $storeId
     * @throws Exception
     * @return array
     */
    public function handlePending(?array $params, ?OrderInterface $order = null, ?int $storeId = null): array
    {
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $monduId = $params['order_uuid'] ?? null;

        if (!$externalReferenceId || !$monduId) {
            throw new Exception('Required params missing');
        }

        if (!$order) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $this->monduFileLogger->info('Processing pending webhook', [
            'external_reference_id' => $externalReferenceId,
            'order_id' => $order->getEntityId(),
            'store_id' => $storeId,
            'current_state' => $order->getState()
        ]);

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
     * @param OrderInterface|null $order
     * @param int|null $storeId
     * @throws Exception
     * @return array
     */
    public function handleConfirmed(?array $params, ?OrderInterface $order = null, ?int $storeId = null): array
    {
        $viban = $params['bank_account']['iban'] ?? null;
        $monduId = $params['order_uuid'] ?? null;
        $externalReferenceId = $params['external_reference_id'] ?? null;

        if (!$viban || !$externalReferenceId) {
            throw new Exception('Required params missing');
        }

        if (!$order) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $this->monduFileLogger->info('Processing confirmed webhook', [
            'external_reference_id' => $externalReferenceId,
            'order_id' => $order->getEntityId(),
            'store_id' => $storeId,
            'viban' => $viban
        ]);

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
     * @param OrderInterface|null $order
     * @param int|null $storeId
     * @throws Exception
     * @return array
     */
    public function handleDeclinedOrCanceled(?array $params, ?OrderInterface $order = null, ?int $storeId = null): array
    {
        $monduId = $params['order_uuid'] ?? null;
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $orderState = $params['order_state'] ?? null;

        if (!$monduId || !$externalReferenceId || !$orderState) {
            throw new Exception('Required params missing');
        }

        if (!$order) {
            return [['message' => 'Order does not exist', 'error' => 0], 200];
        }

        $this->monduFileLogger->info('Processing declined/canceled webhook', [
            'external_reference_id' => $externalReferenceId,
            'order_id' => $order->getEntityId(),
            'store_id' => $storeId,
            'order_state' => $orderState
        ]);

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
     * Validates webhook signature by iterating through all websites.
     * Returns validation result if signature matches, null otherwise (no exceptions thrown).
     *
     * @param string $content
     * @param array $headers
     * @param array $params
     * @return array|null [OrderInterface|null, int|null, int|null] - [order, storeId, websiteId] or null if no match
     */
    private function validateWebhookSignatureAndFindOrder(string $content, array $headers, array $params): ?array
    {
        $externalReferenceId = $params['external_reference_id'] ?? null;
        $receivedSignature = $headers['x-mondu-signature'] ?? $headers['X-Mondu-Signature'] ?? null;

        if (!$externalReferenceId || !$receivedSignature) {
            $this->monduFileLogger->info('Missing required webhook parameters', [
                'has_external_reference_id' => !empty($externalReferenceId),
                'has_signature' => !empty($receivedSignature)
            ]);
            return null;
        }

        $this->monduFileLogger->info('Starting signature validation', [
            'external_reference_id' => $externalReferenceId,
            'received_signature' => $receivedSignature
        ]);

        $websites = $this->storeManager->getWebsites(true);
        $order = null;

        try {
            $order = $this->getOrderByIncrementId($externalReferenceId);
            $this->monduFileLogger->info('Order found by increment_id', [
                'order_id' => $order->getEntityId(),
                'store_id' => $order->getStoreId()
            ]);
        } catch (AuthorizationException $e) {
            $this->monduFileLogger->info('Order not found by increment_id, will check websites anyway');
        }

        foreach ($websites as $website) {
            $websiteId = (int) $website->getId();

            if ($websiteId === 0) {
                continue;
            }

            $this->monduFileLogger->info('Checking signature for website', ['website_id' => $websiteId]);

            try {
                $webhookSecret = $this->getWebhookSecretForWebsite($websiteId);
                
                if (empty($webhookSecret)) {
                    $this->monduFileLogger->info('Webhook secret is empty for website, skipping', ['website_id' => $websiteId]);
                    continue;
                }
                
                $expectedSignature = hash_hmac('sha256', $content, $webhookSecret);

                if ($expectedSignature === $receivedSignature) {
                    $this->monduFileLogger->info('Signature match found for website', [
                        'website_id' => $websiteId,
                        'signature' => $expectedSignature
                    ]);

                    $storeId = null;
                    if ($order) {
                        $storeId = (int) $order->getStoreId();
                    } else {
                        $defaultStore = $website->getDefaultStore();
                        if ($defaultStore) {
                            $storeId = (int) $defaultStore->getId();
                        } else {
                            $stores = $website->getStores();
                            if (!empty($stores)) {
                                $firstStore = reset($stores);
                                $storeId = (int) $firstStore->getId();
                            }
                        }
                    }

                    return [$order, $storeId, $websiteId];
                }
            } catch (Exception $e) {
                $this->monduFileLogger->warning('Error checking signature for website', [
                    'website_id' => $websiteId,
                    'error' => $e->getMessage()
                ]);
                continue;
            }
        }

        $this->monduFileLogger->info('No matching signature found in any website, ignoring webhook');
        return null;
    }

    /**
     * Gets webhook secret for a specific website.
     *
     * @param int $websiteId
     * @return string
     */
    private function getWebhookSecretForWebsite(int $websiteId): string
    {
        $isSandbox = $this->scopeConfig->isSetFlag(
            'payment/mondu/sandbox',
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );
        $mode = $isSandbox ? 'sandbox' : 'live';
        
        $val = $this->scopeConfig->getValue(
            'payment/mondu/' . $mode . '_webhook_secret',
            ScopeInterface::SCOPE_WEBSITE,
            $websiteId
        );

        if (empty($val)) {
            return '';
        }

        return $this->encryptor->decrypt($val);
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
