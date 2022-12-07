<?php
namespace Mondu\Mondu\Helpers;

use Mondu\Mondu\Model\Request\Factory as RequestFactory;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Mondu\Mondu\Helpers\Log as MonduLogger;

use Magento\Sales\Model\Order;

class InvoiceOrderHelper
{
    /**
     * @var ConfigProvider
     */
    private $configProvider;
    /**
     * @var RequestFactory
     */
    private $requestFactory;
    /**
     * @var MonduLogger
     */
    private $monduLogger;

    public function __construct(
        ConfigProvider $configProvider,
        RequestFactory $requestFactory,
        MonduLogger $monduLogger
    )
    {
        $this->configProvider = $configProvider;
        $this->requestFactory = $requestFactory;
        $this->monduLogger = $monduLogger;
    }

    /**
     * creates an invoices for the order
     * @param Order $order
     */
    public function createInvoiceForWholeOrder(Order $order) {
        $monduId = $order->getMonduReferenceId();
        $body = [
            'order_uid' => $monduId,
            'invoice_url' => 'https://not.available',
            'external_reference_id' => $order->getIncrementId(),
            'gross_amount_cents' => round($order->getBaseGrandTotal(), 2) * 100
        ];
        
        $data = $this->requestFactory->create(RequestFactory::SHIP_ORDER)
            ->process($body);
        
        if(!@$data['errors']) {
            $this->monduLogger->updateLogSkipObserver($monduId, true);
            $this->monduLogger->syncOrder($monduId);
        }

        return $data;
    }
}
