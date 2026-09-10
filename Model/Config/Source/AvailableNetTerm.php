<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Config\Source;

use Exception;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\PaymentTerms;

/**
 * Net terms the merchant may offer in the storefront checkout.
 *
 * Populates the multiselect that decides which terms buyers get to choose from, so
 * the list is what the merchant actually holds for the storefront flow rather than
 * a free text number. Read with the widget source: the terms of the admin async
 * flow are a different set and offering one of those to a buyer only earns a 422
 * at order creation.
 *
 * The list is the union across the merchant's countries. Narrowing to the buyer's
 * country happens at checkout, where the country is known.
 */
class AvailableNetTerm implements OptionSourceInterface
{
    /**
     * @param PaymentTerms $paymentTerms
     * @param RequestInterface $request
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly PaymentTerms $paymentTerms,
        private readonly RequestInterface $request,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    public function toOptionArray(): array
    {
        $out = [];
        foreach ($this->paymentTerms->getNetTerms(PaymentTerms::SOURCE_WIDGET, null, $this->getStoreId()) as $term) {
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
