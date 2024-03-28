<?php

namespace Mondu\Mondu\Plugin;

use Mondu\Mondu\Helpers\Log as MonduLogger;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class AddTemplateVariable
{
    /**
     * @var MonduLogger
     */
    private MonduLogger $monduLogger;

    /**
     * @var MonduFileLogger
     */
    private MonduFileLogger $monduFileLogger;

    /**
     * @param  MonduLogger      $monduLogger
     * @param  MonduFileLogger  $monduFileLogger
     */
    public function __construct(
        MonduLogger $monduLogger,
        MonduFileLogger $monduFileLogger
    ) {
        $this->monduLogger = $monduLogger;
        $this->monduFileLogger = $monduFileLogger;
    }

    /**
     * @param  \Magento\Email\Model\Template  $subject
     * @param  array                          $vars
     *
     * @return array[]
     */
    public function beforeSetVars(
        \Magento\Email\Model\Template $subject,
        array $vars
    ) {
        if (!$vars['order'] || !$vars['order']->getMonduReferenceId()) {
            return [$vars];
        }

        try {
            $monduReferenceId = $vars['order']->getMonduReferenceId();
            $monduLog = $this->monduLogger->getLogCollection($monduReferenceId);

            if (!$monduLog) {
                return [$vars];
            }

            $vars['monduDetails'] = $this->prepareHtml($monduLog);
        } catch (\Exception $e) {
            $this->monduFileLogger->critical($e->getMessage());
        }

        return [$vars];
    }

    /**
     * @param $monduLog
     *
     * @return string
     */
    protected function prepareHtml($monduLog)
    {
        $html = '';

        if ($monduLog['payment_method']) {
            $html .= '<strong>Payment Method:</strong> ' . ucwords(strtolower($monduLog['payment_method'])) . '<br />';
        }

        if ($monduLog['invoice_iban']) {
            $html .= '<strong>IBAN:</strong> ' . $monduLog['invoice_iban'] . '<br />';
        }

        if ($monduLog['authorized_net_term']) {
            $html .= '<strong>Net Terms:</strong> ' . $monduLog['authorized_net_term'] . '<br />';
        }

        return $html;
    }
}
