<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer\Config;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Save implements ObserverInterface
{
    private const SUBSCRIPTIONS = ['order/confirmed', 'order/declined', 'order/pending'];

    /**
     * @param ConfigProvider $monduConfig
     * @param PaymentMethod $paymentMethod
     * @param RequestFactory $requestFactory
     * @param MonduFileLogger $monduFileLogger
     * @param StoreManagerInterface $storeManager
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly ConfigProvider $monduConfig,
        private readonly PaymentMethod $paymentMethod,
        private readonly RequestFactory $requestFactory,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
    ) {
    }

    /**
     * Validates Mondu config and registers required webhook keys and subscriptions on save.
     *
     * @param Observer $observer
     * @throws LocalizedException
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $storeId = null;
        if ($observer->getEvent()->getStore()) {
            $storeId = (int) $observer->getEvent()->getStore();
        } elseif ($observer->getEvent()->getData('store')) {
            $storeId = (int) $observer->getEvent()->getData('store');
        }

        // Try multiple ways to get config data
        /** @var \Magento\Config\Model\Config|null $configData */
        $configData = $observer->getEvent()->getData('configData')
            ?? $observer->getEvent()->getData('config_data')
            ?? $observer->getEvent()->getData('object');

        // Primary source for scope in admin: HTTP request (form scope switcher)
        $requestScope = $this->request->getParam('scope');
        $requestScopeId = $this->request->getParam('scope_id');
        $requestWebsite = $this->request->getParam('website');
        $requestStore = $this->request->getParam('store');

        // Try to get scope information from event directly
        $eventScope = $observer->getEvent()->getData('scope');
        $eventScopeId = $observer->getEvent()->getData('scope_id') 
            ?? $observer->getEvent()->getData('website')
            ?? $observer->getEvent()->getData('store_group');

        // Magento often does not populate "scope"/"scope_id" for website-level saves,
        // but passes "website" and/or "store" parameters instead.
        if ($eventScope === null || $eventScope === '') {
            $websiteVal = $observer->getEvent()->getData('website');
            $storeVal = $observer->getEvent()->getData('store');
            if ($websiteVal !== null && $websiteVal !== '') {
                $eventScope = 'websites';
            } elseif ($storeVal !== null && $storeVal !== '') {
                $eventScope = 'stores';
            }
        }

        // Derive scope and store ID for webhook registration
        $websiteId = null;
        $scope = null;
        $scopeId = null;
        
        // Prefer request scope (what user selected in config form), then configData, then event
        if ($requestScope !== null && $requestScope !== '') {
            $scope = $requestScope;
            $scopeId = $requestScopeId !== null && $requestScopeId !== '' ? (int) $requestScopeId : null;
            if ($scope === 'websites' && $scopeId === null && $requestWebsite !== null && $requestWebsite !== '') {
                $scopeId = (int) $requestWebsite;
            }
            if ($scope === 'stores' && $scopeId === null && $requestStore !== null && $requestStore !== '') {
                $scopeId = (int) $requestStore;
            }
        }
        
        if ($scope === null && $configData) {
            $scope = $configData->getScope();
            $scopeId = (int) $configData->getScopeId();
        }
        if ($scope === null && ($eventScope || $eventScopeId !== null)) {
            $scope = $eventScope;
            $scopeId = $eventScopeId !== null ? (int) $eventScopeId : null;
        }
        
        if ($scope && $scopeId !== null) {
            // If saving on website scope, log website information
            if ($scope === 'websites') {
                $websiteId = $scopeId;

                // Always use a store from the selected website for webhook URL and API context,
                // so we get the website's base URL, not Default Config.
                try {
                    $website = $this->storeManager->getWebsite($scopeId);
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
                } catch (Exception $e) {
                    $this->monduFileLogger->error(
                        'Config Save Observer: Failed to get store from website scope',
                        ['website_id' => $scopeId, 'error' => $e->getMessage()]
                    );
                }
            } elseif ($storeId !== null) {
                // If storeId is set, get website ID from store
                try {
                    $store = $this->storeManager->getStore($storeId);
                    $websiteId = (int) $store->getWebsiteId();
                } catch (\Exception $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                    // Website ID not available
                }
            }
        }

        if ($storeId !== null) {
            $this->monduConfig->setContextCode($storeId);
        }

        if (!$this->monduConfig->isActive()) {
            return;
        }

        // Ensure websiteId is set - try to get it from storeId if not already set
        if ($websiteId === null && $storeId !== null) {
            try {
                $store = $this->storeManager->getStore($storeId);
                $websiteId = (int) $store->getWebsiteId();
            } catch (\Exception $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                // Website ID not available
            }
        }

        // Get API key for the specific website (or default if websiteId is null)
        $apiKey = $this->monduConfig->getApiKey($websiteId);
        if (!$apiKey) {
            throw new LocalizedException(__('Cannot enable Mondu payments: API key is missing.'));
        }

        try {
            $this->monduConfig->updateNewOrderStatus();
            $this->paymentMethod->resetAllowedCache();

            $webhookKeysRequest = $this->requestFactory->create(
                RequestFactory::WEBHOOKS_KEYS_REQUEST_METHOD,
                $storeId,
                $websiteId
            );
            $webhookKeysRequest->process();
            $webhookSecret = $webhookKeysRequest->getWebhookSecret();
            $webhookKeysRequest->checkSuccess();
            
            if ($webhookSecret) {
                $webhookKeysRequest->update();
            }

            foreach (self::SUBSCRIPTIONS as $topic) {
                $webhookRequest = $this->requestFactory
                    ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD, $storeId, $websiteId)
                    ->setTopic($topic);
                $webhookRequest->process();
            }

            $this->monduConfig->clearConfigurationCache();
        } catch (Exception $e) {
            $this->monduFileLogger->error('Config Save Observer: Exception', ['message' => $e->getMessage()]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}
