<?php

namespace Mondu\Mondu\Helpers\Logger;

use Mondu\Mondu\Model\Ui\ConfigProvider;

class Logger extends \Monolog\Logger {
    private $monduConfig;

    public function __construct(ConfigProvider $monduConfig, $name, array $handlers = array(), array $processors = array())
    {
        $this->monduConfig = $monduConfig;
        parent::__construct($name, $handlers, $processors);
    }

    public function info($message, array $context = array())
    {
        if($this->monduConfig->getDebug()) {
            parent::info($message, $context);
        }
    }
}
