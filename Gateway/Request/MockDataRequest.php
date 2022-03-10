<?php
namespace Mondu\Mondu\Gateway\Request;

use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Mondu\Mondu\Gateway\Http\Client\ClientMock;

class MockDataRequest implements \Magento\Payment\Gateway\Request\BuilderInterface
{
    const FORCE_RESULT = 'FORCE_RESULT';

    /**
     * @inheritDoc
     */
    public function build(array $buildSubject)
    {
        if (!isset($buildSubject['payment'])
            || !$buildSubject['payment'] instanceof PaymentDataObjectInterface
        ) {
            throw new \InvalidArgumentException('Payment data object should be provided');
        }

        $paymentDO = $buildSubject['payment'];
        $payment = $paymentDO->getPayment();

        $transactionResult = $payment->getAdditionalInformation('transaction_result');
        return [
            self::FORCE_RESULT => $transactionResult === null
                ? ClientMock::SUCCESS
                : $transactionResult
        ];
    }
}
