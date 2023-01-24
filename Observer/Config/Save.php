<?php
namespace Mondu\Mondu\Observer\Config;

use Magento\Framework\Event\Observer;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Save implements ObserverInterface {

    private $_requestFactory;
    private $_monduConfig;
    /**
     * @var PaymentMethod
     */
    private $paymentMethod;
    private $_subscriptions = [
        'order/confirmed',
        'order/declined',
        'order/pending',
        'order/canceled'
    ];

    public function __construct(
        RequestFactory $requestFactory,
        ConfigProvider $monduConfig,
        PaymentMethod $paymentMethod
    ) {
        $this->_requestFactory = $requestFactory;
        $this->_monduConfig = $monduConfig;
        $this->paymentMethod = $paymentMethod;
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

                   $this->_requestFactory->create(RequestFactory::WEBHOOKS_KEYS_REQUEST_METHOD)
                       ->process()
                       ->checkSuccess()
                       ->update();

                   $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/confirmed')
                       ->process();

                   $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/pending')
                       ->process();

                   $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/declined')
                       ->process();

                   $this->_requestFactory
                       ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                       ->setTopic('order/canceled')
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
