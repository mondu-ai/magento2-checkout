<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment;

/**
 * Per-payment-method field registry for admin order create.
 *
 * Fields flow: admin form → DataAssignObserver → payment.additional_information
 * → CreateAsyncOrder payload.
 */
final class AsyncOrderFields
{
    public const FIELD_REGISTRATION_ID         = 'mondu_registration_id';
    public const FIELD_LEGAL_FORM_CATEGORY     = 'mondu_legal_form_category';
    public const FIELD_NET_TERM                = 'mondu_net_term';
    public const FIELD_NUMBER_OF_INSTALLMENTS  = 'mondu_number_of_installments';
    public const FIELD_IBAN                    = 'mondu_iban';
    public const FIELD_ACCOUNT_HOLDER          = 'mondu_account_holder';
    public const FIELD_OWNER_FIRST_NAME        = 'mondu_owner_first_name';
    public const FIELD_OWNER_LAST_NAME         = 'mondu_owner_last_name';
    public const FIELD_OWNER_BIRTH_DATE        = 'mondu_owner_birth_date';

    /**
     * legal_form_category value that makes the `owners` object mandatory.
     */
    public const LEGAL_FORM_CATEGORY_SOLE_TRADER = 'einzelunternehmen';

    /**
     * Owner attributes required by the API when the buyer is a sole trader.
     */
    public const OWNER_FIELDS = [
        self::FIELD_OWNER_FIRST_NAME,
        self::FIELD_OWNER_LAST_NAME,
        self::FIELD_OWNER_BIRTH_DATE,
    ];

    /**
     * Map: Magento payment method code → list of required fields for that method.
     *
     * Derived from Mondu API 422 errors on POST /orders/create_async.
     */
    public const REQUIRED_BY_METHOD = [
        'mondu' => [
            self::FIELD_REGISTRATION_ID,
            self::FIELD_NET_TERM,
        ],
        'mondusepa' => [
            self::FIELD_NET_TERM,
            self::FIELD_IBAN,
            self::FIELD_ACCOUNT_HOLDER,
        ],
        'monduinstallment' => [
            self::FIELD_NUMBER_OF_INSTALLMENTS,
            self::FIELD_IBAN,
            self::FIELD_ACCOUNT_HOLDER,
        ],
        'monduinstallmentbyinvoice' => [
            self::FIELD_NUMBER_OF_INSTALLMENTS,
        ],
        'mondupaynow' => [],
    ];

    /**
     * Map: payment method code → fields that are shown but never enforced.
     *
     * The API accepts these for every method; only `mondu` (invoice) rejects a
     * missing registration_id, and legal_form_category is optional everywhere.
     */
    public const OPTIONAL_BY_METHOD = [
        'mondu' => [
            self::FIELD_LEGAL_FORM_CATEGORY,
        ],
        'mondusepa' => [
            self::FIELD_REGISTRATION_ID,
            self::FIELD_LEGAL_FORM_CATEGORY,
        ],
        'monduinstallment' => [
            self::FIELD_REGISTRATION_ID,
            self::FIELD_LEGAL_FORM_CATEGORY,
        ],
        'monduinstallmentbyinvoice' => [
            self::FIELD_REGISTRATION_ID,
            self::FIELD_LEGAL_FORM_CATEGORY,
        ],
        'mondupaynow' => [
            self::FIELD_REGISTRATION_ID,
            self::FIELD_LEGAL_FORM_CATEGORY,
        ],
    ];

    /**
     * All fields we accept from the admin form (order may include ones not required for
     * the selected method; we store them anyway so the observer stays code-agnostic).
     */
    public const ALL_FIELDS = [
        self::FIELD_REGISTRATION_ID,
        self::FIELD_LEGAL_FORM_CATEGORY,
        self::FIELD_NET_TERM,
        self::FIELD_NUMBER_OF_INSTALLMENTS,
        self::FIELD_IBAN,
        self::FIELD_ACCOUNT_HOLDER,
        self::FIELD_OWNER_FIRST_NAME,
        self::FIELD_OWNER_LAST_NAME,
        self::FIELD_OWNER_BIRTH_DATE,
    ];

    /**
     * @return string[]
     */
    public static function requiredFor(string $methodCode): array
    {
        return self::REQUIRED_BY_METHOD[$methodCode] ?? [];
    }

    /**
     * @return string[]
     */
    public static function optionalFor(string $methodCode): array
    {
        return self::OPTIONAL_BY_METHOD[$methodCode] ?? [];
    }

    /**
     * Every field the admin form should display for the given method.
     *
     * @return string[]
     */
    public static function visibleFor(string $methodCode): array
    {
        return array_merge(self::requiredFor($methodCode), self::optionalFor($methodCode));
    }

    /**
     * Fields that become mandatory because of the selected legal_form_category.
     *
     * @return string[]
     */
    public static function requiredForCategory(?string $category): array
    {
        return $category === self::LEGAL_FORM_CATEGORY_SOLE_TRADER ? self::OWNER_FIELDS : [];
    }

    /**
     * True when the field is only sent along with the `owners` object.
     */
    public static function isOwnerField(string $field): bool
    {
        return in_array($field, self::OWNER_FIELDS, true);
    }

    public static function isMonduMethod(string $methodCode): bool
    {
        return array_key_exists($methodCode, self::REQUIRED_BY_METHOD);
    }
}
