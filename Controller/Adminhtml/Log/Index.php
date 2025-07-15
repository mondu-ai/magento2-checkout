<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page as ResultPage;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Mondu_Mondu::log';
    public const PAGE_TITLE = 'Mondu orders';

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(Context $context, protected PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    /**
     * Creates the Mondu log grid page in the admin panel.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        /** @var ResultPage $resultPage */
        $resultPage = $this->pageFactory->create();
        $resultPage->setActiveMenu(self::ADMIN_RESOURCE);
        $pageTitle = self::PAGE_TITLE;
        $resultPage->addBreadcrumb(__($pageTitle), __($pageTitle))
            ->getConfig()->getTitle()->prepend(__($pageTitle));

        return $resultPage;
    }
}
