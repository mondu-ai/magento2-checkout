<?php
namespace Mondu\Mondu\Gateway\Http\Client;

use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;

class ClientMock implements \Magento\Payment\Gateway\Http\ClientInterface
{
    const SUCCESS = 1;
    const FAILURE = 0;

    private $results = [
        self::SUCCESS,
        self::FAILURE
    ];
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function placeRequest(TransferInterface $transferObject)
    {
        $response = $this->generateResponseForCode(
            $this->getResultCode(
                $transferObject
            )
        );

        $this->logger->debug(
            [
                'request' => $transferObject->getBody(),
                'response' => $response
            ]
        );

        return $response;
    }

    protected function generateResponseForCode($resultCode)
    {

        return array_merge(
            [
                'RESULT_CODE' => $resultCode,
                'TXN_ID' => $this->generateTxnId()
            ],
            $this->getFieldsBasedOnResponseType($resultCode)
        );
    }

    protected function generateTxnId()
    {
        return md5(mt_rand(0, 1000));
    }

    private function getResultCode(TransferInterface $transfer)
    {
        $headers = $transfer->getHeaders();

        if (isset($headers['force_result'])) {
            return (int)$headers['force_result'];
        }

        return $this->results[mt_rand(0, 1)];
    }

    private function getFieldsBasedOnResponseType($resultCode)
    {
        switch ($resultCode) {
            case self::FAILURE:
                return [
                    'FRAUD_MSG_LIST' => [
                        'Stolen card',
                        'Customer location differs'
                    ]
                ];
        }

        return [];
    }
}
