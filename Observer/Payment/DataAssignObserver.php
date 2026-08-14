<?php

declare(strict_types=1);

namespace Mondu\Mondu\Observer\Payment;

use DateTimeImmutable;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Quote\Api\Data\PaymentInterface;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use Mondu\Mondu\Model\Payment\Source\LegalFormCategory;

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

        $this->validate($info->getMethod(), $info);
    }

    /**
     * @throws LocalizedException
     */
    private function validate(string $methodCode, $info): void
    {
        // Validation only applies in admin context — frontend checkout collects
        // these via separate UI flow (not part of this change).
        if ($this->appState->getAreaCode() !== 'adminhtml') {
            return;
        }

        $category = $info->getAdditionalInformation(AsyncOrderFields::FIELD_LEGAL_FORM_CATEGORY);
        $category = $category === null ? null : (string) $category;

        if ($category !== null && $category !== '' && !isset(LegalFormCategory::OPTIONS[$category])) {
            throw new LocalizedException(__('Mondu: unknown legal form category "%1".', $category));
        }

        // Owner attributes only become mandatory for sole traders (einzelunternehmen).
        $required = array_merge(
            AsyncOrderFields::requiredFor($methodCode),
            AsyncOrderFields::requiredForCategory($category)
        );

        $missing = [];
        foreach ($required as $field) {
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

        $this->validateBirthDate($info);
    }

    /**
     * The API expects `owners.0.birth_date` as YYYY-MM-DD.
     *
     * @throws LocalizedException
     */
    private function validateBirthDate($info): void
    {
        $birthDate = $info->getAdditionalInformation(AsyncOrderFields::FIELD_OWNER_BIRTH_DATE);
        if ($birthDate === null || $birthDate === '') {
            return;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d', (string) $birthDate);
        if (!$parsed || $parsed->format('Y-m-d') !== (string) $birthDate) {
            throw new LocalizedException(
                __('Mondu: the owner date of birth must use the format YYYY-MM-DD.')
            );
        }
        if ($parsed > new DateTimeImmutable('today')) {
            throw new LocalizedException(__('Mondu: the owner date of birth cannot be in the future.'));
        }
    }

    private static function humanLabel(string $field): string
    {
        return match ($field) {
            AsyncOrderFields::FIELD_REGISTRATION_ID        => 'Registration ID',
            AsyncOrderFields::FIELD_LEGAL_FORM_CATEGORY    => 'Legal form category',
            AsyncOrderFields::FIELD_NET_TERM               => 'Net term (days)',
            AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS => 'Number of installments',
            AsyncOrderFields::FIELD_IBAN                   => 'IBAN',
            AsyncOrderFields::FIELD_ACCOUNT_HOLDER         => 'Account holder',
            AsyncOrderFields::FIELD_OWNER_FIRST_NAME       => 'Owner first name',
            AsyncOrderFields::FIELD_OWNER_LAST_NAME        => 'Owner last name',
            AsyncOrderFields::FIELD_OWNER_BIRTH_DATE       => 'Owner date of birth',
            default                                        => $field,
        };
    }
}
