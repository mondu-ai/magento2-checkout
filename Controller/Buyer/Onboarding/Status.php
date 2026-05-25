<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer\Onboarding;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Status implements ActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly PageFactory $pageFactory,
        private readonly RedirectFactory $redirectFactory,
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->customerSession->isLoggedIn()) {
            return $this->redirectFactory->create()->setPath('customer/account/login');
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Mondu Trade Account'));

        return $page;
    }
}
