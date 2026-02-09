<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

class Decline extends AbstractPaymentController
{
    /**
     * Redirects the customer back to checkout with a decline message.
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        return $this->redirectWithErrorMessage(
            "Mondu: Unfortunately, we cannot offer you this payment method at the moment.\n"
            . 'Please select another payment option to complete your purchase.'
        );
    }
}
