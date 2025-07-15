<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer\Config;

use Exception;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
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
     */
    public function __construct(
        private readonly ConfigProvider $monduConfig,
        private readonly PaymentMethod $paymentMethod,
        private readonly RequestFactory $requestFactory,
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
        if (!$this->monduConfig->isActive()) {
            return;
        }

        $apiKey = $this->monduConfig->getApiKey();
        if (!$apiKey) {
            throw new LocalizedException(__('Cannot enable Mondu payments: API key is missing.'));
        }

        try {
            $this->monduConfig->updateNewOrderStatus();
            $this->paymentMethod->resetAllowedCache();

            $this->requestFactory->create(RequestFactory::WEBHOOKS_KEYS_REQUEST_METHOD)
                ->process()
                ->checkSuccess()
                ->update();

            foreach (self::SUBSCRIPTIONS as $topic) {
                $this->requestFactory
                    ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                    ->setTopic($topic)
                    ->process();
            }

            $this->monduConfig->clearConfigurationCache();
        } catch (Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }
    }
}
