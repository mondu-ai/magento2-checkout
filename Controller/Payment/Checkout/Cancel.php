<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

class Cancel extends AbstractPaymentController
{
    /**
     * @inheritDoc
     */
    public function execute()
    {
        $this->redirectWithErrorMessage('Mondu: Order has been canceled');
    }
}
