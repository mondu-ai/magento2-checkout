<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Quote\Payment;

use Magento\Quote\Model\Quote\Payment;

/**
 * Admin order create Save controller does:
 *   $quote->getPayment()->addData($_POST['payment']);
 * right after setPaymentData(), so `additional_data` (an array of field values
 * already moved into payment.additional_information by DataAssignObserver)
 * gets written into the `quote_payment.additional_data` TEXT column — which
 * raises "Array to string conversion" in the DB layer.
 *
 * Strip the key before addData persists it. Values are still accessible via
 * $payment->getAdditionalInformation('mondu_*') for downstream use.
 *
 * Scoped to adminhtml in etc/adminhtml/di.xml — storefront serialises
 * additional_data differently and must not be touched.
 */
class StripAdditionalDataPlugin
{
    /**
     * @param Payment $subject
     * @param mixed $arr
     * @return array
     */
    public function beforeAddData(Payment $subject, $arr): array
    {
        if (is_array($arr) && isset($arr['additional_data']) && is_array($arr['additional_data'])) {
            unset($arr['additional_data']);
        }
        return [$arr];
    }
}
