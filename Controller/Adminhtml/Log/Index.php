<?php
namespace Mondu\Mondu\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

class Index extends \Magento\Backend\App\Action
{
    public const ADMIN_RESOURCE = 'Mondu_Mondu::log';

    public const PAGE_TITLE = 'Mondu orders';

    /**
     * @var PageFactory
     */
    protected $_pageFactory;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory
    ) {
        $this->_pageFactory = $pageFactory;
        return parent::__construct($context);
    }

    /**
     * Index action
     *
     * @return Page
     */
    public function execute()
    {
        $resultPage = $this->_pageFactory->create();
        $resultPage->setActiveMenu(static::ADMIN_RESOURCE);
        $pageTitle = static::PAGE_TITLE;
        $resultPage->addBreadcrumb(__($pageTitle), __($pageTitle));
        $resultPage->getConfig()->getTitle()->prepend(__($pageTitle));

        return $resultPage;
    }

    /**
     * Is the user allowed to view the page.
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(static::ADMIN_RESOURCE);
    }
}
