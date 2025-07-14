<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Level;

class Handler extends Base
{
    /**
     * @var int
     */
    protected $loggerType = Level::Info;

    /**
     * @var string
     */
    protected $fileName = '/var/log/mondu.log';
}
