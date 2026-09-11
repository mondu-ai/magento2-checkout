<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Config\Source;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\PaymentTerms;

/**
 * Net terms the merchant may offer for one payment method in the storefront.
 *
 * Populates the multiselect that decides which terms buyers get to choose from.
 * There is one instance per payment method (see the virtual types in etc/di.xml)
 * because a merchant's terms differ per method: the account behind this plugin
 * holds 30, 60 and 90 days for invoice but only 3 days for pay now, and order
 * creation refuses the wrong pairing with 422 "proposed net terms is not
 * available for merchant". A single shared list would offer the merchant terms
 * that only ever produce that error.
 *
 * Read with the widget source, since these terms are offered to buyers in the
 * storefront; the admin order-create screen has its own, async scoped list.
 *
 * The list is the union across the merchant's countries. Narrowing to the
 * buyer's country happens at checkout, where the country is known.
 */
class AvailableNetTerm implements OptionSourceInterface
{
    /**
     * @param PaymentTerms $paymentTerms
     * @param RequestInterface $request
     * @param StoreManagerInterface $storeManager
     * @param string $monduMethod Mondu payment method identifier, e.g. "invoice"
     */
    public function __construct(
        private readonly PaymentTerms $paymentTerms,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
        private readonly string $monduMethod = '',
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $netTerms = $this->paymentTerms->getNetTerms(
            PaymentTerms::SOURCE_WIDGET,
            null,
            $this->getStoreId(),
            $this->monduMethod !== '' ? $this->monduMethod : null
        );

        $out = [];
        foreach ($netTerms as $term) {
            $out[] = ['value' => $term, 'label' => (string) __('%1 days', $term)];
        }

        return $out;
    }

    /**
     * Store whose API key the terms should be read with.
     *
     * The configuration form is scoped by request parameters rather than by an
     * active store, and the API key lives on the website, so a website scope
     * resolves through that website's default store.
     *
     * @return int|null
     */
    private function getStoreId(): ?int
    {
        $store = $this->request->getParam('store');
        if ($store !== null && $store !== '') {
            return (int) $store;
        }

        $website = $this->request->getParam('website');
        if ($website === null || $website === '') {
            return null;
        }

        try {
            $defaultStore = $this->storeManager->getWebsite((int) $website)->getDefaultStore();
        } catch (Exception $e) {
            return null;
        }

        return $defaultStore ? (int) $defaultStore->getId() : null;
    }
}
