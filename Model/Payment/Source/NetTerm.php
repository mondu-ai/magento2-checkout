<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Mondu\Mondu\Helpers\PaymentTerms;

/**
 * Net terms the merchant may actually use, read from GET /api/v1/payment_terms.
 */
class NetTerm implements OptionSourceInterface, ArgumentInterface
{
    /**
     * @param PaymentTerms $paymentTerms
     */
    public function __construct(private readonly PaymentTerms $paymentTerms)
    {
    }

    public function toOptionArray(): array
    {
        $out = [];
        foreach ($this->paymentTerms->getNetTerms() as $netTerm) {
            $out[] = ['value' => $netTerm, 'label' => (string) __('%1 days', $netTerm)];
        }
        return $out;
    }
}
