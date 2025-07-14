<?php

declare(strict_types=1);

namespace Mondu\Mondu\Service;

use Magento\Framework\Exception\LocalizedException;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class TransactionService
{
    /**
     * @param ABTesting $aBTesting
     * @param MonduFileLogger $monduFileLogger
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        private readonly ABTesting $aBTesting,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly RequestFactory $requestFactory,
    ) {
    }

    /**
     * Creates a Mondu transaction and processes its result and potential errors.
     *
     * @param array $requestData
     * @throws LocalizedException
     * @return array
     */
    public function createTransaction(array $requestData): array
    {
        $result = $this->requestFactory->create(RequestFactory::TRANSACTIONS_REQUEST_METHOD)
            ->process($requestData);

        $response = $this->aBTesting->formatApiResult($result);
        $this->monduFileLogger->info('Received Mondu transaction response', $response);
        $this->processDecline($result['body']['order'] ?? [], $response);
        $this->processError($result, $response);

        return $response;
    }

    /**
     * Flags response as declined if the transaction state is DECLINED.
     *
     * @param array $order
     * @param array $response
     * @return void
     */
    private function processDecline(array $order, array &$response): void
    {
        if (($order['state'] ?? null) === OrderHelper::DECLINED) {
            $response['error'] = true;
            $response['message'] = __('Order has been declined.');
        }
    }

    /**
     * Updates response message if an error is present in the result.
     *
     * @param array $result
     * @param array $response
     * @return void
     */
    private function processError(array $result, array &$response): void
    {
        if (!$response['error']) {
            return;
        }

        $response['message'] = $this->getErrorMessage($result)
            ?: __('Error placing an order. Please try again later.');
    }

    /**
     * Extracts a formatted error message from the Mondu API result.
     *
     * @param array $result
     * @return string
     */
    private function getErrorMessage(array $result): string
    {
        $error = $result['body']['errors'][0] ?? null;
        return $error
            ? str_replace('.', ' ', $error['name'] ?? '') . ' ' . ($error['details'] ?? '')
            : '';
    }
}
