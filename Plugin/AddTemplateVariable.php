<?php

namespace Mondu\Mondu\Plugin;

use Mondu\Mondu\Helpers\Log as MonduLogger;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class AddTemplateVariable
{
    public const VARIABLE_STRUCTURE = '<strong>%s:</strong> %s<br />';

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

            $vars['monduPaymentMethod'] = $vars['monduInvoiceIban'] = $vars['monduNetTerms'] = '';

            if ($monduLog['payment_method']) {
                $vars['monduPaymentMethod'] = sprintf(
                    self::VARIABLE_STRUCTURE,
                    __('Payment Method'),
                    ucwords(strtolower($monduLog['payment_method']))
                );
            }

            if ($monduLog['invoice_iban']) {
                $vars['monduInvoiceIban'] = sprintf(
                    self::VARIABLE_STRUCTURE,
                    __('IBAN'),
                    ucwords(strtolower($monduLog['invoice_iban']))
                );
            }

            if ($monduLog['authorized_net_term']) {
                $vars['monduNetTerms'] = sprintf(
                    self::VARIABLE_STRUCTURE,
                    __('Payment term'),
                    $monduLog['authorized_net_term'] . ' ' . __('days')
                );
            }
        } catch (\Exception $e) {
            $this->monduFileLogger->critical($e->getMessage());
        }

        return [$vars];
    }
}
