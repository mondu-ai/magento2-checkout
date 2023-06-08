<?php

namespace Mondu\Mondu\Model\Payment;

use Magento\Payment\Model\InfoInterface;

class Mondu extends \Magento\Payment\Model\Method\AbstractMethod
{
    public const PAYMENT_METHOD_MONDU_CODE = 'mondu';

    /**
     * @var string
     */
    protected $_code = 'mondu';

    /**
     * Authorize
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
     * SetCode
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
