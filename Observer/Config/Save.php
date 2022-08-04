<?php
namespace Mondu\Mondu\Observer\Config;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Save implements ObserverInterface {

    private $_requestFactory;
    private $_monduConfig;
    private $topics = [
        'order',
        'invoice'
    ];

    public function __construct(RequestFactory $requestFactory, ConfigProvider $monduConfig) {
        $this->_requestFactory = $requestFactory;
        $this->_monduConfig = $monduConfig;
    }

    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {

        if ($this->_monduConfig->isActive()) {
           if ($this->_monduConfig->getApiKey()) {
               try {
                   $this->_requestFactory->create(RequestFactory::WEBHOOKS_KEYS_REQUEST_METHOD)
                       ->process()
                       ->checkSuccess()
                       ->update();

                   foreach ($this->topics as $topic) {
                       $this->_requestFactory
                           ->create(RequestFactory::WEBHOOKS_REQUEST_METHOD)
                           ->setTopic($topic)
                           ->process();
                   }

               } catch (\Exception $e) {
                   throw new LocalizedException(__($e->getMessage()));
               }
           } else {
               throw new LocalizedException(__('Cant enable Mondu payments API key is missing'));
           }
        }
    }
}
