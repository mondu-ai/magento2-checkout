<?php

declare(strict_types=1);

namespace Mondu\Mondu\Controller\Buyer\Onboarding;

use Exception;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Initiate implements ActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RequestFactory $requestFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly MessageManager $messageManager,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly UrlInterface $urlBuilder,
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            $this->messageManager->addErrorMessage(__('Please log in to start onboarding.'));
            return $redirect->setPath('customer/account/login');
        }

        $customer = $this->customerSession->getCustomer();

        try {
            $request = $this->requestFactory->create(RequestFactory::TRADE_ACCOUNT);
            $result = $request->process([
                'external_reference_id' => $customer->getEmail(),
                'redirect_urls' => [
                    'success_url' => $this->urlBuilder->getUrl('mondu/buyer_onboarding/success'),
                    'cancel_url' => $this->urlBuilder->getUrl('mondu/buyer_onboarding/cancel'),
                    'declined_url' => $this->urlBuilder->getUrl('mondu/buyer_onboarding/decline'),
                ],
                'applicant' => [
                    'first_name' => $customer->getFirstname(),
                    'last_name' => $customer->getLastname(),
                    'email' => $customer->getEmail(),
                ],
                'language' => 'de',
            ]);

            $hostedPageUrl = $result['hosted_page_url'] ?? null;

            if (!$hostedPageUrl) {
                throw new Exception('No hosted_page_url in response');
            }

            $this->monduFileLogger->info('Buyer onboarding initiated', [
                'customer_email' => $customer->getEmail(),
                'hosted_page_url' => $hostedPageUrl,
            ]);

            return $redirect->setUrl($hostedPageUrl);
        } catch (Exception $e) {
            $this->monduFileLogger->error('Failed to initiate buyer onboarding', [
                'error' => $e->getMessage(),
                'customer_email' => $customer->getEmail(),
            ]);
            $this->messageManager->addErrorMessage(__('Could not start Mondu onboarding. Please try again later.'));
            return $redirect->setPath('customer/account');
        }
    }
}
