<?php

namespace Mondu\Mondu\Model\Payment;

use Magento\Payment\Model\InfoInterface;

class Mondu extends \Magento\Payment\Model\Method\AbstractMethod
{
    const PAYMENT_METHOD_MONDU_CODE = 'mondu';

    protected $_code = self::PAYMENT_METHOD_MONDU_CODE;

    public function authorize(InfoInterface $payment, $amount)
    {
        return $this;
    }
}
