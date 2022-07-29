<?php

namespace Mondu\Mondu\Model\Payment;

use Magento\Payment\Model\InfoInterface;

class Mondu extends \Magento\Payment\Model\Method\AbstractMethod
{
    const PAYMENT_METHOD_MONDU_CODE = 'mondu';

    protected $_code = 'mondu';

    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }

    public function setCode($code) {
        $this->_code = $code;
        return $this;
    }
}
