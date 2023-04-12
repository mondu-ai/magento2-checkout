<?php
namespace Mondu\Mondu\Observer\Config;

use Magento\Framework\Event\Observer;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Save implements ObserverInterface
{
    private $_requestFactory;
    private $_monduConfig;
    /**
     * @var PaymentMethod
     */
    private $paymentMethod;

    /**
     * @var string[]
     */
    private $_subscriptions = [
        'order/confirmed',
        'order/declined',
        'order/pending',
        'order/canceled'
    ];

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        RequestFactory $requestFactory,
        ConfigProvider $monduConfig,
        PaymentMethod $paymentMethod,
        StoreManagerInterface $storeManager
    ) {
        $this->_requestFactory = $requestFactory;
        $this->_monduConfig = $monduConfig;
        $this->paymentMethod = $paymentMethod;
        $this->storeManager = $storeManager;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        if ($this->_monduConfig->isActive()) {
            if ($this->_monduConfig->getApiKey()) {
                try {
                    $this->_monduConfig->updateNewOrderStatus();
                    $this->paymentMethod->resetAllowedCache();

                    $storeId = $this->storeManager->getStore()->getId();
                    $this->_requestFactory->create(RequestFactory::WEBHOOKS_KEYS_REQUEST_METHOD)
                       ->process()
                       ->setStore($storeId)
                       ->checkSuccess()
                       ->update();

                    $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setStore($storeId)
                       ->setTopic('order/confirmed')
                       ->process();

                    $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/pending')
                       ->setStore($storeId)
                       ->process();

                    $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/declined')
                       ->setStore($storeId)
                       ->process();

                    $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/canceled')
                       ->setStore($storeId)
                       ->process();

                    $this->_monduConfig->clearConfigurationCache();
                } catch (\Exception $e) {
                    throw new LocalizedException(__($e->getMessage()));
                }
            } else {
                throw new LocalizedException(__('Cant enable Mondu payments API key is missing'));
            }
        }
    }
}
