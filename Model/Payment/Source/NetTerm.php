<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment\Source;

use Exception;
use Magento\Backend\Model\Session\Quote as QuoteSession;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Mondu\Mondu\Helpers\PaymentTerms;

/**
 * Net terms the merchant may actually use, read from GET /api/v1/payment_terms.
 *
 * On the admin order-create screen the list is narrowed to the billing country
 * of the current quote: Mondu offers different terms per country, and picking
 * one the merchant has no agreement for only earns a 422 from the API.
 */
class NetTerm implements OptionSourceInterface, ArgumentInterface
{
    /**
     * @param PaymentTerms $paymentTerms
     * @param QuoteSession $quoteSession Admin order-create session (proxied, see etc/di.xml)
     */
    public function __construct(
        private readonly PaymentTerms $paymentTerms,
        private readonly QuoteSession $quoteSession,
    ) {
    }

    public function toOptionArray(): array
    {
        $out = [];
        $netTerms = $this->paymentTerms->getNetTerms(
            PaymentTerms::SOURCE_ASYNC,
            $this->getBillingCountryId(),
            $this->getStoreId()
        );

        foreach ($netTerms as $netTerm) {
            $out[] = ['value' => $netTerm, 'label' => (string) __('%1 days', $netTerm)];
        }
        return $out;
    }

    /**
     * The option the form should preselect, or null when there is nothing to offer.
     *
     * @return int|null
     */
    public function getDefaultNetTerm(): ?int
    {
        return $this->paymentTerms->getDefaultNetTerm(
            PaymentTerms::SOURCE_ASYNC,
            $this->getBillingCountryId(),
            $this->getStoreId()
        );
    }

    /**
     * Billing country of the quote being edited, or null outside the admin order-create flow.
     *
     * @return string|null
     */
    private function getBillingCountryId(): ?string
    {
        try {
            $countryId = $this->quoteSession->getQuote()->getBillingAddress()->getCountryId();
        } catch (Exception $e) {
            return null;
        }

        return $countryId ? (string) $countryId : null;
    }

    /**
     * Store the quote belongs to, so the terms are read with that store's API key.
     *
     * @return int|null
     */
    private function getStoreId(): ?int
    {
        try {
            $storeId = $this->quoteSession->getStoreId();
        } catch (Exception $e) {
            return null;
        }

        return $storeId !== null ? (int) $storeId : null;
    }
}
