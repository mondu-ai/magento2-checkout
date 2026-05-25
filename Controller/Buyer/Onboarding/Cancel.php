<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer\Onboarding;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class Cancel implements ActionInterface
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
        $this->monduFileLogger->info('Buyer onboarding cancelled', [
            'customer_id' => $this->customerSession->getCustomerId(),
        ]);

        $this->messageManager->addNoticeMessage(
            __('Mondu onboarding was cancelled. You can try again at any time.')
        );

        return $this->redirectFactory->create()->setPath('customer/account');
    }
}
