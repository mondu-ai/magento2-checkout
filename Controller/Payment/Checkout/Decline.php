<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

class Decline extends AbstractPaymentController
{
    /**
     * @inheritDoc
     */
    public function execute()
    {
        $this->redirectWithErrorMessage('Mondu: Order has been declined');
    }
}
