<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Checkout\Controller\Index\Index;

use Magento\Checkout\Controller\Index\Index;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\App\RequestInterface;

class DeclineMessagePlugin
{
    private const MESSAGES = [
        'decline' => "Mondu: Unfortunately, we cannot offer you this payment method at the moment.\n"
            . 'Please select another payment option to complete your purchase.',
        'cancel' => 'Mondu: Order has been canceled',
    ];

    public function __construct(
        private readonly MessageManagerInterface $messageManager,
        private readonly RequestInterface $request,
    ) {
    }

    /**
     * When returning from Mondu decline/cancel, add error message.
     * Session may be lost when coming from external Mondu domain, so we pass mondu_error param.
     * Message is added here and page loads normally (no redirect) so message displays.
     *
     * @param Index $subject
     * @param callable $proceed
     * @return ResultInterface
     */
    public function aroundExecute(Index $subject, callable $proceed): ResultInterface
    {
        $monduError = $this->request->getParam('mondu_error');
        if (isset(self::MESSAGES[$monduError])) {
            $this->messageManager->addErrorMessage(__(self::MESSAGES[$monduError]));
        }

        return $proceed();
    }
}
