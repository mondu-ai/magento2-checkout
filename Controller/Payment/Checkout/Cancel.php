<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

class Cancel extends AbstractPaymentController
{
    /**
     * Redirects the customer to the cart with a cancellation message.
     *
     * @return ResponseInterface|ResultInterface
     */
    public function execute(): ResponseInterface|ResultInterface
    {
        return $this->redirectWithErrorMessage('Mondu: Order has been canceled');
    }
}
