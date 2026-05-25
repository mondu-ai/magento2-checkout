<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer\Payment;

use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;

/**
 * Copies Mondu admin-order-create form fields from POST data into
 * payment.additional_information, then validates presence of required fields
 * for the selected payment method.
 *
 * Wired for payment_method_assign_data_<code> events (see etc/events.xml) so
 * it runs only for Mondu methods.
 */
class DataAssignObserver extends AbstractDataAssignObserver implements ObserverInterface
{
    public function __construct(
        private readonly AppState $appState,
    ) {}

    public function execute(Observer $observer): void
    {
        $data = $this->readDataArgument($observer);
        $info = $this->readPaymentModelArgument($observer);

        // Magento passes the POSTed method data under PaymentInterface::KEY_ADDITIONAL_DATA,
        // or sometimes nested in the DataObject directly.
        $additional = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA) ?? [];
        if (!is_array($additional) || $additional === []) {
            $additional = $data->getData() ?? [];
        }

        foreach (AsyncOrderFields::ALL_FIELDS as $field) {
            if (!array_key_exists($field, $additional)) {
                continue;
            }
            $value = $additional[$field];
            if (is_string($value)) {
                $value = trim($value);
                if ($field === AsyncOrderFields::FIELD_IBAN) {
                    $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
                }
            }
            if ($value === '' || $value === null) {
                continue;
            }
            $info->setAdditionalInformation($field, $value);
        }

        $this->validateRequired($info->getMethod(), $info);
    }

    /**
     * @throws LocalizedException
     */
    private function validateRequired(string $methodCode, $info): void
    {
        // Validation only applies in admin context — frontend checkout collects
        // these via separate UI flow (not part of this change).
        if ($this->appState->getAreaCode() !== 'adminhtml') {
            return;
        }

        $missing = [];
        foreach (AsyncOrderFields::requiredFor($methodCode) as $field) {
            $value = $info->getAdditionalInformation($field);
            if ($value === null || $value === '' || $value === []) {
                $missing[] = self::humanLabel($field);
            }
        }

        if ($missing !== []) {
            throw new LocalizedException(
                __('Mondu: please fill the following required fields: %1', implode(', ', $missing))
            );
        }
    }

    private static function humanLabel(string $field): string
    {
        return match ($field) {
            AsyncOrderFields::FIELD_LEGAL_FORM             => 'Legal form',
            AsyncOrderFields::FIELD_NET_TERM               => 'Net term (days)',
            AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS => 'Number of installments',
            AsyncOrderFields::FIELD_IBAN                   => 'IBAN',
            AsyncOrderFields::FIELD_ACCOUNT_HOLDER         => 'Account holder',
            default                                         => $field,
        };
    }
}
