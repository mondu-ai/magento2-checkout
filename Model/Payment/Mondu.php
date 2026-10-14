<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Payment;

use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Mondu\Mondu\Block\Info;

class Mondu extends AbstractMethod
{
    public const PAYMENT_METHOD_MONDU_CODE = 'mondu';

    /**
     * @var string
     */
    protected $_code = 'mondu';

    /**
     * @var bool
     */
    protected $_canUseInternal = true;

    /**
     * Shows the net term the order is settled on in the order view, emails and invoice PDF.
     *
     * @var string
     */
    protected $_infoBlockType = Info::class;

    /**
     * Authorize.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return $this|Mondu
     */
    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    /**
     * SetCode.
     *
     * @param string $code
     * @return $this
     */
    public function setCode($code)
    {
        $this->_code = $code;
        return $this;
    }
}
