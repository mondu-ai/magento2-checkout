<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Magento\Framework\Serialize\SerializerInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\BuyerFactory;
use Mondu\Mondu\Model\ResourceModel\Buyer as BuyerResource;
use Mondu\Mondu\Model\ResourceModel\Buyer\CollectionFactory as BuyerCollectionFactory;

class BuyerStatus
{
    /**
     * @param BuyerFactory $buyerFactory
     * @param BuyerResource $buyerResource
     * @param BuyerCollectionFactory $buyerCollectionFactory
     * @param SerializerInterface $serializer
     * @param MonduFileLogger $logger
     */
    public function __construct(
        private readonly BuyerFactory $buyerFactory,
        private readonly BuyerResource $buyerResource,
        private readonly BuyerCollectionFactory $buyerCollectionFactory,
        private readonly SerializerInterface $serializer,
        private readonly MonduFileLogger $logger,
    ) {
    }

    /**
     * @param int $customerId
     * @param int $websiteId
     * @return array|null
     */
    public function getBuyerForCustomer(int $customerId, int $websiteId): ?array
    {
        $collection = $this->buyerCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', $customerId);
        $collection->addFieldToFilter('website_id', $websiteId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }

        return $item->getData();
    }

    /**
     * @param int $customerId
     * @param int $websiteId
     * @param string $externalRefId
     * @param string $onboardingType
     * @return void
     */
    public function saveBuyerRecord(
        int $customerId,
        int $websiteId,
        string $externalRefId,
        string $onboardingType
    ): void {
        $existing = $this->getBuyerForCustomer($customerId, $websiteId);

        $buyer = $this->buyerFactory->create();
        if ($existing) {
            $this->buyerResource->load($buyer, $existing['entity_id']);
        }

        $buyer->setData('customer_id', $customerId);
        $buyer->setData('website_id', $websiteId);
        $buyer->setData('external_reference_id', $externalRefId);
        $buyer->setData('onboarding_type', $onboardingType);
        $buyer->setData('state', 'pending');
        $buyer->setData('buyer_uuid', null);

        $this->buyerResource->save($buyer);

        $this->logger->info('Buyer record saved', [
            'customer_id' => $customerId,
            'website_id' => $websiteId,
            'external_reference_id' => $externalRefId,
            'onboarding_type' => $onboardingType,
        ]);
    }

    /**
     * @param string $externalReferenceId
     * @param string $buyerUuid
     * @param string $state
     * @param string|null $companyName
     * @return void
     */
    public function updateBuyerFromWebhook(
        string $externalReferenceId,
        string $buyerUuid,
        string $state,
        ?string $companyName = null
    ): void {
        $collection = $this->buyerCollectionFactory->create();
        $collection->addFieldToFilter('external_reference_id', $externalReferenceId);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            $this->logger->warning('Buyer record not found for webhook', [
                'external_reference_id' => $externalReferenceId,
                'buyer_uuid' => $buyerUuid,
            ]);
            return;
        }

        $buyer = $this->buyerFactory->create();
        $this->buyerResource->load($buyer, $item->getId());

        $buyer->setData('buyer_uuid', $buyerUuid);
        $buyer->setData('state', $state);
        if ($companyName) {
            $buyer->setData('company_name', $companyName);
        }

        $this->buyerResource->save($buyer);

        $this->logger->info('Buyer record updated from webhook', [
            'external_reference_id' => $externalReferenceId,
            'buyer_uuid' => $buyerUuid,
            'state' => $state,
        ]);
    }

    /**
     * @param string $buyerUuid
     * @param array $limitData
     * @return void
     */
    public function updatePurchasingLimit(string $buyerUuid, array $limitData): void
    {
        $collection = $this->buyerCollectionFactory->create();
        $collection->addFieldToFilter('buyer_uuid', $buyerUuid);
        $collection->setPageSize(1);

        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return;
        }

        $buyer = $this->buyerFactory->create();
        $this->buyerResource->load($buyer, $item->getId());

        $purchasingLimit = $limitData['purchasing_limit'] ?? $limitData;

        $buyer->setData('purchasing_limit_cents', $purchasingLimit['purchasing_limit_cents'] ?? null);
        $buyer->setData('balance_cents', $purchasingLimit['balance_cents'] ?? null);
        $buyer->setData('max_purchase_value_cents', $purchasingLimit['max_purchase_value_cents'] ?? null);

        if (isset($purchasingLimit['eligible_terms'])) {
            $buyer->setData('eligible_terms', $this->serializer->serialize($purchasingLimit['eligible_terms']));
        }

        $buyer->setData('purchasing_limit_updated_at', date('Y-m-d H:i:s'));

        $this->buyerResource->save($buyer);
    }

    /**
     * @param int $customerId
     * @param int $websiteId
     * @return string|null
     */
    public function getAcceptedBuyerUuidForCustomer(int $customerId, int $websiteId): ?string
    {
        $buyer = $this->getBuyerForCustomer($customerId, $websiteId);
        if ($buyer && $buyer['state'] === 'accepted' && !empty($buyer['buyer_uuid'])) {
            return $buyer['buyer_uuid'];
        }

        return null;
    }
}
