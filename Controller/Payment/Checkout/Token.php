<?php

namespace Mondu\Mondu\Controller\Payment\Checkout;

use Magento\Framework\Webapi\Response;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class Token extends AbstractPaymentController
{
    /**
     * @inheritDoc
     */
    public function execute()
    {
        $userAgent = $this->request->getHeaders()->toArray()['User-Agent'] ?? null;
        $this->monduFileLogger->info('Token controller, trying to create the order');
        $paymentMethod = $this->request->getParam('payment_method') ?? null;
        $result = $this->requestFactory->create(RequestFactory::TRANSACTIONS_REQUEST_METHOD)
            ->process([
                'email' => $this->request->getParam('email'),
                'user-agent' => $userAgent,
                'payment_method' => $paymentMethod
            ]);

        $response = $this->aBTesting->formatApiResult($result);

        $this->monduFileLogger->info('Token controller got a result ', $response);
        if (!$response['error']) {
            $this->handleOrderDecline($result['body']['order'], $response);
        } else {
            $response['message'] = $this->handleOrderError($result);
        }

        if ($response['error'] && !$response['message']) {
            $response['message'] = __('Error placing an order Please try again later.');
        }
        return $this->jsonResultFactory->create()
            ->setHttpResponseCode(Response::HTTP_OK)
            ->setData($response);
    }

    /**
     * HandleOrderDecline
     *
     * @param array  $monduOrder
     * @param array &$response
     * @return void
     */
    public function handleOrderDecline($monduOrder, &$response)
    {
        if ($monduOrder['state'] === OrderHelper::DECLINED) {
            $response['error'] = 1;
            $response['message'] = __('Order has been declined');
        }
    }

    /**
     * HandleOrderError
     *
     * @param array $response
     * @return string
     */
    public function handleOrderError($response): string
    {
        $message = '';
        if (isset($response['body']['errors']) && isset($response['body']['errors'][0])) {
            $message .= str_replace(
                '.',
                ' ',
                $response['body']['errors'][0]['name']
            ) . ' ' . $response['body']['errors'][0]['details'];
        }
        return $message;
    }
}
