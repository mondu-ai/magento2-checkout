<?php
namespace Mondu\Mondu\Controller\Payment\Checkout;

use \Magento\Framework\Controller\Result\JsonFactory;
use \Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Webapi\Response;
use Mondu\Mondu\Model\Request\Transactions;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Token implements \Magento\Framework\App\ActionInterface {
    private $request;
    private $jsonResultFactory;
    private $transactions;
    private $requestFactory;
    private $monduLogger;

    public function __construct(
        JsonFactory $jsonResultFactory,
        RequestInterface $request,
        Transactions $transactions,
        RequestFactory $requestFactory
    ) {
        $this->jsonResultFactory = $jsonResultFactory;
        $this->request = $request;
        $this->transactions = $transactions;
        $this->requestFactory = $requestFactory;
    }

    private function getRequest() {
        return $this->request;
    }

    public function execute() {
        $result = $this->requestFactory
            ->create(RequestFactory::TRANSACTIONS_REQUEST_METHOD)
            ->process(['email' => $this->request->getParam('email')]);
        $response = [
            'error' => $result['error'],
            'message' => $result['message'],
            'token' => @$result['body']['order']['uuid']
        ];

        if(!$response['error']) {
            $this->handleOrderDecline($result['body']['order'], $response);
        } else {
            $response['message'] = $this->handleOrderError($result);
        }

        if($response['error'] && !$response['message']) {
            $response['message'] = __('Error placing an order Please try again later.');
        }
        return $this->jsonResultFactory->create()
            ->setHttpResponseCode(Response::HTTP_OK)
            ->setData($response);
    }

    /**
     */
    public function handleOrderDecline($monduOrder, &$response) {
        if($monduOrder['state'] === 'declined') {
            $declineReason = $monduOrder['decline_reason'];
            $response['error'] = 1;

            switch ($declineReason) {
                case 'buyer_not_identified':
                    $response['message'] = __('Unable to identify the buyer');
                    break;
                case 'address_missing':
                    $response['message'] = __('The address is missing');
                    break;
                case 'buyer_fraud':
                    $response['message'] = __('Potential fraud detected');
                    break;
                case 'risk_scoring_failed':
                    $response['message'] = __('Risk scoring failed');
                    break;
                case 'buyer_limit_exceeded':
                    $response['message'] = __('Buyer limit exceeded');
                    break;
                default:
                    $response['message'] = __('Error placing an order');
            }
        }
    }

    public function handleOrderError($response): string
    {
        $message = '';
        if(@$response['body']['errors'] && @$response['body']['errors'][0]) {
            $message.= str_replace('.', ' ', $response['body']['errors'][0]['name']).' '.$response['body']['errors'][0]['details'];
        }
        return $message;
    }
}
