<?php

namespace Mondu\Mondu\Plugin;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Mondu\Mondu\Helpers\Log as MonduLogger;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class AddTemplateVariable
{
    const MONDU_UK_SORT_CODE = '185008';
    const MONDU_EN_ACCOUNT_HOLDER = 'Mondu Capital S.à r.l.';
    const MONDU_FR_DE_ACCOUNT_HOLDER = 'Mondu Capital Sàrl';
    const MONDU_NL_ACCOUNT_HOLDER = 'Mondu Capital S.à r.l';
    const MONDU_EN_BANK_NAME = 'Citibank N.A.';
    const MONDU_EN_BIC = 'CITINL2X';
    const MONDU_DE_NL_BIC = 'HYVEDEMME40';
    const MONDU_FR_BIC = 'CITIFRPP';
    const UK_COUNTRY_CODE = 'UK';
    const DE_COUNTRY_CODE = 'DE';
    const FR_COUNTRY_CODE = 'FR';
    const NL_COUNTRY_CODE = 'NL';

    /**
     * @var MonduLogger
     */
    private MonduLogger $monduLogger;

    /**
     * @var MonduFileLogger
     */
    private MonduFileLogger $monduFileLogger;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param  MonduLogger           $monduLogger
     * @param  MonduFileLogger       $monduFileLogger
     * @param  ScopeConfigInterface  $scopeConfig
     */
    public function __construct(
        MonduLogger $monduLogger,
        MonduFileLogger $monduFileLogger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->monduLogger = $monduLogger;
        $this->monduFileLogger = $monduFileLogger;
        $this->scopeConfig = $scopeConfig;
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
            $vars['monduDetails'] = '';
            $monduReferenceId = $vars['order']->getMonduReferenceId();
            $monduLog = $this->monduLogger->getLogCollection($monduReferenceId);

            if (!$monduLog) {
                return [$vars];
            }

            if (!$monduLog['external_data'] || !is_string($monduLog['external_data'])) {
                return [$vars];
            }

            $externalData = json_decode($monduLog['external_data'] , true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [$vars];
            }

            $billingAddress = $vars['order']->getBillingAddress();
            $vars['monduDetails'] = $this->getInvoiceDetails([
                'countryId' => $externalData['buyer_country_code'] ?: $billingAddress->getCountryId(),
                'merchant_company_name' => $externalData['merchant_company_name'],
                'bank_account' => $externalData['bank_account'],
                'invoiceId' => $vars['invoice']->getIncrementId(),
                'iban' => $monduLog['invoice_iban'],
                'paymentMethod' => $vars['order']->getPayment()->getMethodInstance()->getTitle(),
                'netTerms' => isset($monduLog['authorized_net_term']) ? $monduLog['authorized_net_term'] : ''
            ]);
        } catch (\Exception $e) {
            $this->monduFileLogger->critical($e->getMessage());
        }

        return [$vars];
    }

    /**
     * @param $invoiceDetails
     *
     * @return string
     */
    protected function getInvoiceDetails($invoiceData): string
    {
        switch ($invoiceData['paymentMethod']) {
            case $this->scopeConfig->getValue('payment/mondu/title', ScopeInterface::SCOPE_STORE):
                $invoiceDetails = $this->getPayLaterViaBankTransferDetails($invoiceData);
                break;
            case $this->scopeConfig->getValue('payment/mondusepa/title', ScopeInterface::SCOPE_STORE):
                $invoiceDetails  = __('This invoice was created in accordance with the general terms and conditions of <strong>%1</strong> and <strong>Mondu GmbH</strong> for the purchase on account payment model.', $invoiceData['merchant_company_name']) . '<br/>';
                $invoiceDetails .= __('Since you have chosen the payment method to purchase on account with payment via SEPA direct debit through Mondu, the invoice amount will be debited from your bank account on the due date.') . '<br/>';
                $invoiceDetails .= __('Before the amount is debited from your account, you will receive notice of the direct debit. Kindly make sure you have sufficient funds in your account.') . '<br/>';
                break;
            default:
                $invoiceDetails  = __('This invoice was created in accordance with the general terms and conditions of <strong>%1</strong> and <strong>Mondu GmbH</strong> for the instalment payment model.', $invoiceData['merchant_company_name']) . '<br/>';
                $invoiceDetails .= __('Since you have chosen the instalment payment method via SEPA direct debit through Mondu, the individual installments will be debited from your bank account on the due date.') . '<br/>';
                $invoiceDetails .= __('Before the amounts are debited from your account, you will receive notice regarding the direct debit. Kindly make sure you have sufficient funds in your account. In the event of changes to your order, the instalment plan will be adjusted to reflect the new order total.') . '<br/>';
                break;
        }

        return $invoiceDetails;
    }

    /**
     * @param $invoiceData
     *
     * @return string
     */
    protected function getPayLaterViaBankTransferDetails($invoiceData)
    {
        $invoiceDetails = __('This invoice is created in accordance with the terms and conditions of <strong>%1</strong> modified by <strong>Mondu GmbH</strong> payment terms. Please pay to the following account:', $invoiceData['merchant_company_name']) . '<br/>';

        switch ($invoiceData['countryId']) {
            case self::UK_COUNTRY_CODE:
                $invoiceDetails .= __('<strong>Account holder:</strong> %1', $invoiceData['bank_account']['account_holder'] ?: self::MONDU_EN_ACCOUNT_HOLDER) . '<br/>';
                $invoiceDetails .= __('<strong>Bank:</strong> %1', $invoiceData['bank_account']['bank'] ?: self::MONDU_EN_BANK_NAME) . '<br/>';
                $invoiceDetails .= __('<strong>Sort Code:</strong> %1', $invoiceData['bank_account']['sort_code'] ?: self::MONDU_UK_SORT_CODE) . '<br/>';
                $invoiceDetails .= __('<strong>Account Number:</strong> %1', $invoiceData['bank_account']['account_number']) . '<br/>';
                $invoiceDetails .= __('<strong>IBAN:</strong> %1', $invoiceData['iban']) . '<br/>';
                $invoiceDetails .= __('<strong>BIC:</strong> %1', $invoiceData['bank_account']['bic'] ?: self::MONDU_EN_BIC) . '<br/>';
                break;
            case self::DE_COUNTRY_CODE:
                $invoiceDetails .= __('<strong>Account holder:</strong> %1', self::MONDU_FR_DE_ACCOUNT_HOLDER) . '<br/>';
                $invoiceDetails .= __('<strong>IBAN:</strong> %1', $invoiceData['iban']) . '<br/>';
                $invoiceDetails .= __('<strong>BIC:</strong> %1', $invoiceData['bank_account']['bic'] ?: self::MONDU_DE_NL_BIC) . '<br/>';
                break;
            case self::FR_COUNTRY_CODE:
                $invoiceDetails .= __('<strong>Account holder:</strong> %1', self::MONDU_FR_DE_ACCOUNT_HOLDER) . '<br/>';
                $invoiceDetails .= __('<strong>IBAN:</strong> %1', $invoiceData['iban']) . '<br/>';
                $invoiceDetails .= __('<strong>BIC:</strong> %1', $invoiceData['bank_account']['bic'] ?: self::MONDU_FR_BIC) . '<br/>';
                break;
            case self::NL_COUNTRY_CODE:
                $invoiceDetails .= __('<strong>Account holder:</strong> %1', self::MONDU_NL_ACCOUNT_HOLDER) . '<br/>';
                $invoiceDetails .= __('<strong>IBAN:</strong> %1', $invoiceData['iban']) . '<br/>';
                $invoiceDetails .= __('<strong>BIC:</strong> %1', $invoiceData['bank_account']['bic'] ?: self::MONDU_DE_NL_BIC) . '<br/>';
                break;
            default:
                $invoiceDetails .= __('<strong>Account holder:</strong> %1', $invoiceData['bank_account']['account_holder'] ?: self::MONDU_EN_ACCOUNT_HOLDER) . '<br/>';
                $invoiceDetails .= __('<strong>Bank:</strong> %1', $invoiceData['bank_account']['bank'] ?: self::MONDU_EN_BANK_NAME) . '<br/>';
                $invoiceDetails .= __('<strong>IBAN:</strong> %1', $invoiceData['iban']) . '<br/>';
                $invoiceDetails .= __('<strong>BIC:</strong> %1', $invoiceData['bank_account']['bic'] ?: self::MONDU_EN_BIC) . '<br/>';
                break;
        }

        $invoiceDetails .= __('<strong>Payment reference:</strong> %1', $invoiceData['invoiceId']) . '<br/>';
        $invoiceDetails .= __('<strong>Payment term:</strong> %1 days', $invoiceData['netTerms']) .  '<br/>';

        return $invoiceDetails;
    }
}
