<?php
namespace Mondu\Mondu\Model;

use Magento\Framework\Model\AbstractModel;

class Log extends AbstractModel
{
    /**
     * Construct
     *
     * @return void
     */
    public function _construct()
    {
        $this->_init(\Mondu\Mondu\Model\ResourceModel\Log::class);
    }
}
