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
    public const FIELD_LEGAL_FORM              = 'mondu_legal_form';
    public const FIELD_NET_TERM                = 'mondu_net_term';
    public const FIELD_NUMBER_OF_INSTALLMENTS  = 'mondu_number_of_installments';
    public const FIELD_IBAN                    = 'mondu_iban';
    public const FIELD_ACCOUNT_HOLDER          = 'mondu_account_holder';

    /**
     * Map: Magento payment method code → list of required fields for that method.
     *
     * Derived from Mondu API 422 errors on POST /orders/create_async.
     */
    public const REQUIRED_BY_METHOD = [
        'mondu' => [
            self::FIELD_LEGAL_FORM,
            self::FIELD_NET_TERM,
        ],
        'mondusepa' => [
            self::FIELD_LEGAL_FORM,
            self::FIELD_NET_TERM,
            self::FIELD_IBAN,
            self::FIELD_ACCOUNT_HOLDER,
        ],
        'monduinstallment' => [
            self::FIELD_LEGAL_FORM,
            self::FIELD_NUMBER_OF_INSTALLMENTS,
            self::FIELD_IBAN,
            self::FIELD_ACCOUNT_HOLDER,
        ],
        'monduinstallmentbyinvoice' => [
            self::FIELD_LEGAL_FORM,
            self::FIELD_NUMBER_OF_INSTALLMENTS,
        ],
        'mondupaynow' => [],
    ];

    /**
     * All fields we accept from the admin form (order may include ones not required for
     * the selected method; we store them anyway so the observer stays code-agnostic).
     */
    public const ALL_FIELDS = [
        self::FIELD_LEGAL_FORM,
        self::FIELD_NET_TERM,
        self::FIELD_NUMBER_OF_INSTALLMENTS,
        self::FIELD_IBAN,
        self::FIELD_ACCOUNT_HOLDER,
    ];

    /**
     * @return string[]
     */
    public static function requiredFor(string $methodCode): array
    {
        return self::REQUIRED_BY_METHOD[$methodCode] ?? [];
    }

    public static function isMonduMethod(string $methodCode): bool
    {
        return array_key_exists($methodCode, self::REQUIRED_BY_METHOD);
    }
}
