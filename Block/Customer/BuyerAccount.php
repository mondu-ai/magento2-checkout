<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Customer;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\BuyerStatus;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class BuyerAccount extends Template
{
    /**
     * @param Context $context
     * @param CustomerSession $customerSession
     * @param BuyerStatus $buyerStatus
     * @param ConfigProvider $configProvider
     * @param StoreManagerInterface $storeManager
     * @param PriceCurrencyInterface $priceCurrency
     * @param SerializerInterface $serializer
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly BuyerStatus $buyerStatus,
        private readonly ConfigProvider $configProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly SerializerInterface $serializer,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return array|null
     */
    public function getBuyerData(): ?array
    {
        $customerId = (int) $this->customerSession->getCustomerId();
        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        return $this->buyerStatus->getBuyerForCustomer($customerId, $websiteId);
    }

    /**
     * @return string
     */
    public function getBuyerState(): string
    {
        $buyer = $this->getBuyerData();
        return $buyer ? $buyer['state'] : 'none';
    }

    /**
     * @return string
     */
    public function getOnboardingType(): string
    {
        return $this->configProvider->getBuyerOnboardingType();
    }

    /**
     * @return string
     */
    public function getApplyUrl(): string
    {
        return $this->getUrl('mondu/buyer/apply');
    }

    /**
     * @param int|null $cents
     * @return string
     */
    public function formatCents(?int $cents): string
    {
        if ($cents === null) {
            return '-';
        }
        return $this->priceCurrency->format($cents / 100, false);
    }

    /**
     * @return array
     */
    public function getEligibleTerms(): array
    {
        $buyer = $this->getBuyerData();
        if (!$buyer || empty($buyer['eligible_terms'])) {
            return [];
        }

        try {
            return $this->serializer->unserialize($buyer['eligible_terms']);
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * @return string
     */
    public function getGdprUrl(): string
    {
        return 'https://www.mondu.ai/en-gb/gdpr-notification-for-buyers-uk/';
    }
}
