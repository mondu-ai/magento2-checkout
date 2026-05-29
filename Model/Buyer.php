<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model;

use Magento\Framework\Model\AbstractModel;
use Mondu\Mondu\Model\ResourceModel\Buyer as BuyerResource;

class Buyer extends AbstractModel
{
    /**
     * @return void
     */
    public function _construct(): void
    {
        $this->_init(BuyerResource::class);
    }
}
