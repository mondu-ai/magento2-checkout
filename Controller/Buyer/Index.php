<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer;

use Exception;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\BuyerStatus;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Index implements ActionInterface
{
    /**
     * @param PageFactory $pageFactory
     * @param CustomerSession $customerSession
     * @param RedirectFactory $redirectFactory
     * @param BuyerStatus $buyerStatus
     * @param ConfigProvider $configProvider
     * @param StoreManagerInterface $storeManager
     * @param RequestFactory $requestFactory
     * @param MonduFileLogger $logger
     */
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly CustomerSession $customerSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly BuyerStatus $buyerStatus,
        private readonly ConfigProvider $configProvider,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestFactory $requestFactory,
        private readonly MonduFileLogger $logger,
    ) {
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        if (!$this->customerSession->isLoggedIn()) {
            $redirect = $this->redirectFactory->create();
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        if (!$this->configProvider->isBuyerOnboardingEnabled()) {
            $redirect = $this->redirectFactory->create();
            $redirect->setPath('customer/account');
            return $redirect;
        }

        $customerId = (int) $this->customerSession->getCustomerId();
        $websiteId = (int) $this->storeManager->getStore()->getWebsiteId();
        $buyer = $this->buyerStatus->getBuyerForCustomer($customerId, $websiteId);

        if ($buyer && $buyer['state'] === 'accepted' && !empty($buyer['buyer_uuid'])) {
            $shouldRefresh = empty($buyer['purchasing_limit_updated_at'])
                || (strtotime($buyer['purchasing_limit_updated_at']) < (time() - 300));

            if ($shouldRefresh) {
                try {
                    $storeId = (int) $this->storeManager->getStore()->getId();
                    $request = $this->requestFactory->create(
                        RequestFactory::PURCHASING_LIMIT,
                        $storeId,
                        $websiteId
                    );
                    $result = $request->process(['buyer_uuid' => $buyer['buyer_uuid']]);
                    if ($result) {
                        $this->buyerStatus->updatePurchasingLimit($buyer['buyer_uuid'], $result);
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Failed to refresh purchasing limit', [
                        'buyer_uuid' => $buyer['buyer_uuid'],
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set(__('Mondu Trade Account'));
        return $page;
    }
}
