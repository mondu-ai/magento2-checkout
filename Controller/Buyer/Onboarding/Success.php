<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer\Onboarding;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class Success implements ActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly MonduFileLogger $monduFileLogger,
    ) {
    }

    public function execute(): ResultInterface
    {
        $this->monduFileLogger->info('Buyer onboarding success callback', [
            'customer_id' => $this->customerSession->getCustomerId(),
        ]);

        $this->messageManager->addSuccessMessage(
            __('Your Mondu account application has been submitted successfully. You will be notified once approved.')
        );

        return $this->redirectFactory->create()->setPath('customer/account');
    }
}
