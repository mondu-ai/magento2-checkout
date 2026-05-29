<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer;

use Exception;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\BuyerStatus;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Apply implements ActionInterface
{
    /**
     * @param JsonFactory $jsonFactory
     * @param CustomerSession $customerSession
     * @param CustomerRepositoryInterface $customerRepository
     * @param AddressRepositoryInterface $addressRepository
     * @param RequestInterface $request
     * @param ConfigProvider $configProvider
     * @param RequestFactory $requestFactory
     * @param BuyerStatus $buyerStatus
     * @param StoreManagerInterface $storeManager
     * @param UrlInterface $urlBuilder
     * @param MonduFileLogger $logger
     */
    public function __construct(
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly RequestInterface $request,
        private readonly ConfigProvider $configProvider,
        private readonly RequestFactory $requestFactory,
        private readonly BuyerStatus $buyerStatus,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly MonduFileLogger $logger,
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setData(['success' => false, 'message' => __('Please log in.')]);
        }

        if (!$this->configProvider->isBuyerOnboardingEnabled()) {
            return $result->setData(['success' => false, 'message' => __('Buyer onboarding is not enabled.')]);
        }

        $gdprConsent = $this->request->getParam('gdpr_consent');
        if (!$gdprConsent) {
            return $result->setData([
                'success' => false,
                'message' => __('Please accept the data processing consent before applying.'),
            ]);
        }

        try {
            $customerId = (int) $this->customerSession->getCustomerId();
            $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
            $storeId = (int) $this->storeManager->getStore()->getId();

            $existing = $this->buyerStatus->getBuyerForCustomer($customerId, $websiteId);
            if ($existing && $existing['state'] === 'accepted') {
                return $result->setData([
                    'success' => false,
                    'message' => __('You already have an approved Mondu trade account.'),
                ]);
            }

            if ($existing && $existing['state'] === 'pending') {
                return $result->setData([
                    'success' => false,
                    'message' => __('Your application is already being reviewed.'),
                ]);
            }

            $customer = $this->customerRepository->getById($customerId);
            $billingAddress = null;
            $defaultBillingId = $customer->getDefaultBilling();
            if ($defaultBillingId) {
                try {
                    $billingAddress = $this->addressRepository->getById((int) $defaultBillingId);
                } catch (Exception $e) {
                    $billingAddress = null;
                }
            }

            $externalRefId = $customerId . '_' . $websiteId;
            $onboardingType = $this->configProvider->getBuyerOnboardingType();

            $payload = [
                'external_reference_id' => $externalRefId,
                'redirect_urls' => [
                    'success_url' => $this->urlBuilder->getUrl('mondu/buyer/success'),
                    'cancel_url' => $this->urlBuilder->getUrl('mondu/buyer/cancel'),
                    'declined_url' => $this->urlBuilder->getUrl('mondu/buyer/declined'),
                ],
                'applicant' => [
                    'first_name' => $customer->getFirstname(),
                    'last_name' => $customer->getLastname(),
                    'email' => $customer->getEmail(),
                ],
            ];

            if ($billingAddress) {
                $street = $billingAddress->getStreet();
                $payload['company_details'] = [
                    'name' => $billingAddress->getCompany() ?: ($customer->getFirstname() . ' ' . $customer->getLastname()),
                    'registration_address' => [
                        'country_code' => $billingAddress->getCountryId(),
                        'address_line1' => $street[0] ?? '',
                        'city' => $billingAddress->getCity(),
                        'zip_code' => $billingAddress->getPostcode(),
                    ],
                ];

                if (isset($street[1]) && $street[1]) {
                    $payload['company_details']['registration_address']['address_line2'] = $street[1];
                }

                $phone = $billingAddress->getTelephone();
                if ($phone) {
                    $payload['applicant']['phone'] = $phone;
                }

                $vatId = $billingAddress->getVatId() ?: $customer->getTaxvat();
                if ($vatId) {
                    $payload['company_details']['company_registration_id'] = $vatId;
                }
            }

            $locale = $this->storeManager->getStore()->getConfig('general/locale/code') ?: 'en_US';
            $langCode = substr($locale, 0, 2);
            $supportedLanguages = ['en', 'de', 'nl', 'fr', 'es', 'it'];
            if (in_array($langCode, $supportedLanguages)) {
                $payload['language'] = $langCode;
            }

            $factoryMethod = $onboardingType === 'trade_account'
                ? RequestFactory::TRADE_ACCOUNT
                : RequestFactory::HOSTED_ONBOARDING;

            $request = $this->requestFactory->create($factoryMethod, $storeId, $websiteId);
            $apiResult = $request->process($payload);

            $this->buyerStatus->saveBuyerRecord($customerId, $websiteId, $externalRefId, $onboardingType);

            $this->logger->info('Buyer onboarding initiated', [
                'customer_id' => $customerId,
                'onboarding_type' => $onboardingType,
                'external_reference_id' => $externalRefId,
            ]);

            return $result->setData([
                'success' => true,
                'hosted_page_url' => $apiResult['hosted_page_url'],
            ]);
        } catch (Exception $e) {
            $this->logger->error('Buyer onboarding failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $result->setData([
                'success' => false,
                'message' => __('An error occurred while processing your application. Please try again.'),
            ]);
        }
    }
}
