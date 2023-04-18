<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

interface MonduObserverInterface extends ObserverInterface
{
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer);

    /**
     * @param Observer $observer
     * @return void
     */
    public function _execute(Observer $observer);
}
