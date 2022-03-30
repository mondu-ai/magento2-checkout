<?php
namespace Mondu\Mondu\Model;

class Log extends \Magento\Framework\Model\AbstractModel {
    public function _construct() {
        $this->_init("Mondu\Mondu\Model\ResourceModel\Log");
    }
}