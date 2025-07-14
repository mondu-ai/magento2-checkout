<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

interface MonduObserverInterface extends ObserverInterface
{
    /**
     * Default execute function.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void;

    /**
     * Mondu Plugin execute function.
     *
     * @param Observer $observer
     * @return void
     */
    public function _execute(Observer $observer): void;
}
