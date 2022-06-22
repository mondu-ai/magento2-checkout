<?php
namespace Mondu\Mondu\Helpers;

use Mondu\Mondu\Model\PaymentMethodList;

class DataPlugin {
    public function __construct(PaymentMethodList $paymentMethodList)
    {
        $this->paymentMethodList = $paymentMethodList;
    }

    public function afterGetPaymentMethods(\Magento\Payment\Helper\Data $subject, $result) {
        $result['mondu-sepa'] = $result['mondu'];
        return $result;
    }
    public function aroundGetMethodInstance(\Magento\Payment\Helper\Data $subject, callable $proceed, $code)
    {
        if (false === strpos($code, 'mondu-')) {
            return $proceed($code);
        }

        return $this->paymentMethodList->getPaymentMethod($code);
    }
}
