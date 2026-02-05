<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer\Config;

use Exception;
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
     */
    public function __construct(
        private readonly ConfigProvider $monduConfig,
        private readonly PaymentMethod $paymentMethod,
        private readonly RequestFactory $requestFactory,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly StoreManagerInterface $storeManager,
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
        $this->monduFileLogger->info('Config Save Observer: Starting execution');
        $storeId = null;
        if ($observer->getEvent()->getStore()) {
            $storeId = (int) $observer->getEvent()->getStore();
        } elseif ($observer->getEvent()->getData('store')) {
            $storeId = (int) $observer->getEvent()->getData('store');
        }

        // Derive store ID when config is saved on website scope
        /** @var \Magento\Config\Model\Config|null $configData */
        $configData = $observer->getEvent()->getData('configData')
            ?? $observer->getEvent()->getData('config_data');

        if ($configData && $storeId === null) {
            $scope = $configData->getScope();
            $scopeId = (int) $configData->getScopeId();

            if ($scope === 'websites') {
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

                    $this->monduFileLogger->info(
                        'Config Save Observer: Derived store ID from website scope',
                        ['website_id' => $scopeId, 'store_id' => $storeId]
                    );
                } catch (Exception $e) {
                    $this->monduFileLogger->error(
                        'Config Save Observer: Failed to derive store from website scope',
                        ['website_id' => $scopeId, 'error' => $e->getMessage()]
                    );
                }
            }
        }

        $this->monduFileLogger->info('Config Save Observer: Store ID detected', ['store_id' => $storeId]);
        if ($storeId !== null) {
            $this->monduConfig->setContextCode($storeId);
        }

        if (!$this->monduConfig->isActive()) {
            $this->monduFileLogger->info('Config Save Observer: Mondu is not active, skipping');
            return;
        }

        $this->monduFileLogger->info('Config Save Observer: Mondu is active, proceeding with webhook registration');

        $apiKey = $this->monduConfig->getApiKey();
        if (!$apiKey) {
            throw new LocalizedException(__('Cannot enable Mondu payments: API key is missing.'));
        }

        try {
            $this->monduFileLogger->info('Config Save Observer: Starting webhook registration process');
            
            $this->monduConfig->updateNewOrderStatus();
            $this->paymentMethod->resetAllowedCache();

            $this->monduFileLogger->info('Config Save Observer: Creating webhook keys request');
            $this->requestFactory->create(RequestFactory::WEBHOOKS_KEYS_REQUEST_METHOD, $storeId)
                ->process()
                ->checkSuccess()
                ->update();
            $this->monduFileLogger->info('Config Save Observer: Webhook keys request completed successfully');

            $this->monduFileLogger->info('Config Save Observer: Starting webhook subscriptions', ['topics' => self::SUBSCRIPTIONS]);
            foreach (self::SUBSCRIPTIONS as $topic) {
                $this->monduFileLogger->info('Config Save Observer: Creating webhook subscription request', ['topic' => $topic]);
                $webhookRequest = $this->requestFactory
                    ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD, $storeId)
                    ->setTopic($topic);
                
                $this->monduFileLogger->info('Config Save Observer: About to call process() for webhook request', ['topic' => $topic]);
                $webhookRequest->process();
                $this->monduFileLogger->info('Config Save Observer: Webhook subscription request completed', ['topic' => $topic]);
            }

            $this->monduConfig->clearConfigurationCache();
            $this->monduFileLogger->info('Config Save Observer: All webhook registrations completed successfully');
        } catch (Exception $e) {
            $this->monduFileLogger->error('Config Save Observer: Exception occurred', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}