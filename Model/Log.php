<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Mondu\Mondu\Model\ResourceModel\Log as LogResource;

class Log extends AbstractModel
{
    /**
     * Initializes the associated resource model.
     *
     * @throws LocalizedException
     * @return void
     */
    public function _construct(): void
    {
        $this->_init(LogResource::class);
    }
}
