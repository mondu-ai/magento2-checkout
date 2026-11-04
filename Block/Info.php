<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block;

use Magento\Framework\App\Area;
use Magento\Framework\DataObject;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Block\Info as PaymentInfo;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Mondu\Mondu\Helpers\Log as MonduLogHelper;

/**
 * Payment information shown for a Mondu order.
 *
 * The info block of every Mondu method settled on a net term, set as
 * $_infoBlockType on the method model, so the term reaches the order view, the
 * order emails and the invoice PDF through one path: Magento renders this block
 * into all three.
 */
class Info extends PaymentInfo
{
    /**
     * Template the parent block renders with, a table of the details.
     */
    private const DEFAULT_TEMPLATE = 'Magento_Payment::info/default.phtml';

    /**
     * Admin template: the details as plain lines, like the rest of the order view.
     */
    private const ADMIN_TEMPLATE = 'Mondu_Mondu::info/mondu.phtml';

    /**
     * @param Context $context
     * @param MonduLogHelper $monduLogHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly MonduLogHelper $monduLogHelper,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Uses the admin template when the block renders for the admin.
     *
     * Decided by the design area rather than by DI, so an order email sent from the
     * admin, which renders under the storefront design, keeps the standard template.
     * The PDF sets its own template and is left alone.
     *
     * @return string
     */
    public function getTemplate()
    {
        $template = parent::getTemplate();
        if ($template === self::DEFAULT_TEMPLATE && $this->_design->getArea() === Area::AREA_ADMINHTML) {
            return self::ADMIN_TEMPLATE;
        }

        return $template;
    }

    /**
     * Adds the net term the order is settled on.
     *
     * The term Mondu authorized is the one the invoice has to state. It is known
     * only once Mondu has decided, so until then the term picked in the checkout
     * or on the admin order form stands in for it.
     *
     * @param DataObject|array|null $transport
     * @return DataObject
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        if ($this->_paymentSpecificInformation !== null) {
            return $this->_paymentSpecificInformation;
        }

        $transport = parent::_prepareSpecificInformation($transport);

        $info = $this->getInfo();
        if (!$info instanceof OrderPayment || !$info->getOrder()) {
            return $transport;
        }

        $netTerm = $this->monduLogHelper->getNetTermForOrder($info->getOrder());
        if ($netTerm !== null) {
            $transport->setData((string) __('Payment term'), (string) __('%1 days', $netTerm));
        }

        return $transport;
    }
}
