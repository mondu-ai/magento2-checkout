<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers\Logger;

use Mondu\Mondu\Model\Ui\ConfigProvider;
use Monolog\Logger as MonologLogger;
use Stringable;

class Logger extends MonologLogger
{
    /**
     * @var string
     */
    private string $fallbackName = 'MONDU';

    /**
     * @param ConfigProvider $monduConfig
     * @param string|null $name
     * @param array $handlers
     * @param array $processors
     */
    public function __construct(
        private readonly ConfigProvider $monduConfig,
        ?string $name = null,
        array $handlers = [],
        array $processors = [],
    ) {
        parent::__construct($name ?? $this->fallbackName, $handlers, $processors);
    }

    /**
     *  Adds a log record at the INFO level.
     *
     * @param string|Stringable $message
     * @param array $context
     * @return void
     */
    public function info($message, array $context = []): void
    {
        if (!$this->monduConfig->isDebugModeEnabled()) {
            return;
        }

        parent::info($message, $context);
    }
}
