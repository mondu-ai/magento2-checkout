<?php

declare(strict_types=1);

namespace Mondu\Mondu\Service;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Mondu\Mondu\Helpers\ABTesting\ABTesting;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class TransactionService
{
    /**
     * @param ABTesting $aBTesting
     * @param MonduFileLogger $monduFileLogger
     * @param RequestFactory $requestFactory
     * @param ConfigProvider $configProvider
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly ABTesting $aBTesting,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly RequestFactory $requestFactory,
        private readonly ConfigProvider $configProvider,
        private readonly StoreManagerInterface $storeManager,
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
        $storeId = isset($requestData['store_id']) ? (int) $requestData['store_id'] : null;

        $websiteId = null;
        if ($storeId !== null) {
            try {
                $websiteId = (int) $this->storeManager->getStore($storeId)->getWebsiteId();
            } catch (\Exception $e) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
                // website ID not resolvable
            }
        }

        $request = $this->requestFactory->create(RequestFactory::TRANSACTIONS_REQUEST_METHOD, $storeId);

        $apiKey = $this->configProvider->getApiKey($websiteId);
        $apiKeyHint = $apiKey ? '...' . substr($apiKey, -6) : null;

        $this->monduFileLogger->info('Mondu checkout initiated', [
            'store_id'   => $storeId,
            'website_id' => $websiteId,
            'mode'       => $this->configProvider->getMode(),
            'api_key'    => $apiKeyHint,
        ]);

        $result = $request->process($requestData);

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
            $response['message'] = __(
                "Mondu: Unfortunately, we cannot offer you this payment method at the moment.\n"
                . 'Please select another payment option to complete your purchase.'
            );
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
