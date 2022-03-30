<?php
namespace Mondu\Mondu\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferInterface;
use Mondu\Mondu\Gateway\Request\MockDataRequest;

class TransferFactory implements \Magento\Payment\Gateway\Http\TransferFactoryInterface
{
    private $transferBuilder;

    public function __construct(TransferBuilder $transferBuilder)
    {
        $this->transferBuilder = $transferBuilder;
    }

    public function create(array $request)
    {
        return $this->transferBuilder
            ->setBody($request)
            ->setMethod('POST')
            ->setHeaders(
                [
                    'force_result' => $request[MockDataRequest::FORCE_RESULT] ?? null
                ]
            )
            ->build();
    }
}
