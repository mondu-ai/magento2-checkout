<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Adminhtml\Order\Create;

use Exception;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Session\Quote as QuoteSession;
use Mondu\Mondu\Helpers\BackendOrders;

/**
 * Host block for the Mondu fieldset on the admin order-create screen.
 *
 * Renders nothing unless admin order creation is switched on and available, so
 * merchants without the async source never see the fields.
 */
class MonduFields extends Template
{
    /**
     * @param Context $context
     * @param BackendOrders $backendOrders
     * @param QuoteSession $quoteSession
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly BackendOrders $backendOrders,
        private readonly QuoteSession $quoteSession,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Billing country of the quote being edited, or null when there is none yet.
     *
     * @return string|null
     */
    public function getBillingCountryId(): ?string
    {
        try {
            $countryId = $this->quoteSession->getQuote()->getBillingAddress()->getCountryId();
        } catch (Exception $e) {
            return null;
        }

        return $countryId ? (string) $countryId : null;
    }

    /**
     * Renders the fieldset only when admin order creation is switched on and available.
     *
     * @return string
     */
    protected function _toHtml()
    {
        if (!$this->backendOrders->isActive($this->getStoreId())) {
            return '';
        }

        return parent::_toHtml();
    }

    /**
     * Store of the quote being edited, falling back to the admin's current store.
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
