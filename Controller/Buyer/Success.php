<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;

class Success implements ActionInterface
{
    /**
     * @param RedirectFactory $redirectFactory
     * @param MessageManager $messageManager
     */
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $this->messageManager->addSuccessMessage(
            __('Your application has been submitted. You will be notified once it is reviewed.')
        );

        $redirect = $this->redirectFactory->create();
        $redirect->setPath('mondu/buyer/index');
        return $redirect;
    }
}
