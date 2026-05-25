<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Buyer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class OnboardingStatus extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    public function getBuyerState(): ?string
    {
        $customer = $this->getCustomerData();
        if (!$customer) {
            return null;
        }

        $attr = $customer->getCustomAttribute('mondu_buyer_state');
        return $attr ? $attr->getValue() : null;
    }

    public function getBuyerUuid(): ?string
    {
        $customer = $this->getCustomerData();
        if (!$customer) {
            return null;
        }

        $attr = $customer->getCustomAttribute('mondu_buyer_uuid');
        return $attr ? $attr->getValue() : null;
    }

    public function getInitiateUrl(): string
    {
        return $this->getUrl('mondu/buyer_onboarding/initiate');
    }

    private function getCustomerData(): ?\Magento\Customer\Api\Data\CustomerInterface
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }

        try {
            return $this->customerRepository->getById(
                (int) $this->customerSession->getCustomerId()
            );
        } catch (\Exception $e) {
            return null;
        }
    }
}
