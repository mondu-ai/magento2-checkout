<?php

namespace Mondu\Mondu\Helpers\Logger;

use Mondu\Mondu\Model\Ui\ConfigProvider;

class Logger extends \Monolog\Logger
{
    /**
     * @var ConfigProvider
     */
    private $monduConfig;

    /**
     * @var string
     */
    protected $fallbackName = "MONDU";

    /**
     * @param ConfigProvider $monduConfig
     * @param string $name
     * @param array $handlers
     * @param array $processors
     */
    public function __construct(
        ConfigProvider $monduConfig,
        $name,
        array $handlers = [],
        array $processors = []
    ) {
        $this->monduConfig = $monduConfig;
        parent::__construct($name ?? $this->fallbackName, $handlers, $processors);
    }

    /**
     *  Adds a log record at the INFO level.
     *
     * @param string $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        if ($this->monduConfig->getDebug()) {
            parent::info($message, $context);
        }
    }
}
