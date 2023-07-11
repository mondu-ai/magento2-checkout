<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

class Cancel extends AbstractPaymentController
{
    /**
     * @inheritDoc
     */
    public function execute()
    {
        return $this->redirectWithErrorMessage('Mondu: Order has been canceled');
    }
}
