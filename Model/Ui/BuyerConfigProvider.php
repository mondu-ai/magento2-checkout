<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\BuyerStatus;

class BuyerConfigProvider implements ConfigProviderInterface
{
    /**
     * @param CustomerSession $customerSession
     * @param BuyerStatus $buyerStatus
     * @param ConfigProvider $configProvider
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly BuyerStatus $buyerStatus,
        private readonly ConfigProvider $configProvider,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        $buyerData = null;

        if ($this->configProvider->isBuyerOnboardingEnabled()) {
            $customerId = $this->customerSession->getCustomerId();
            if ($customerId) {
                try {
                    $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
                    $buyer = $this->buyerStatus->getBuyerForCustomer((int) $customerId, $websiteId);
                    if ($buyer && $buyer['state'] === 'accepted') {
                        $buyerData = [
                            'is_onboarded' => true,
                            'purchasing_limit_cents' => $buyer['purchasing_limit_cents'] ?? null,
                            'balance_cents' => $buyer['balance_cents'] ?? null,
                            'max_purchase_value_cents' => $buyer['max_purchase_value_cents'] ?? null,
                        ];
                    }
                } catch (\Exception $e) {
                    // Silently fail — buyer data is optional at checkout
                }
            }
        }

        return [
            'payment' => [
                'mondu_buyer' => $buyerData,
            ],
        ];
    }
}
