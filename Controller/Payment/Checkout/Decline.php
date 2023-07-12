<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

class Decline extends AbstractPaymentController
{
    /**
     * @inheritDoc
     */
    public function execute()
    {
        return $this->redirectWithErrorMessage('Mondu: Order has been declined');
    }
}
