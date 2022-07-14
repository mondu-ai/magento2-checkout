<?php
namespace Mondu\Mondu\Helpers;

class PaymentMethod {
    const PAYMENTS = ['mondu', 'mondusepa'];

    const LABELS = [
        'mondusepa' => 'SEPA Direct Debit',
        'mondu' => 'Rechnungskauf'
    ];

    public function getPayments() {
        return self::PAYMENTS;
    }

    public function getAllowed() {
        return ['mondu', 'mondusepa'];
    }

    public function isMondu($method): bool
    {
        $code = $method->getCode() ?? $method->getMethod();

        return in_array($code, self::PAYMENTS);
    }

    public function getCode($method) {
        return $method->getCode() ?? $method->getMethod();
    }

    public function getLabel($code) {
        return @self::LABELS[$code];
    }
}
