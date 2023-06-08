<?php
namespace Mondu\Mondu\Observer;

use Magento\Framework\Event\Observer;
use Exception;
use \Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Mondu\Mondu\Helpers\ContextHelper;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class CancelOrder extends MonduObserver
{
    /**
     * @var string
     */
    protected $name = 'CancelOrder';

    /**
     * @var RequestFactory
     */
    private $requestFactory;

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @var ManagerInterface
     */
    private $messageManager;

    /**
     * @param PaymentMethod $paymentMethodHelper
     * @param MonduFileLogger $monduFileLogger
     * @param ContextHelper $contextHelper
     * @param RequestFactory $requestFactory
     * @param ManagerInterface $messageManager
     */
    public function __construct(
        PaymentMethod $paymentMethodHelper,
        MonduFileLogger $monduFileLogger,
        ContextHelper $contextHelper,
        RequestFactory $requestFactory,
        ManagerInterface $messageManager
    ) {
        parent::__construct(
            $paymentMethodHelper,
            $monduFileLogger,
            $contextHelper
        );

        $this->monduFileLogger = $monduFileLogger;
        $this->requestFactory = $requestFactory;
        $this->messageManager = $messageManager;
    }

    /**
     * Execute
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function _execute(Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $monduId = $order->getData('mondu_reference_id');

        try {
            if (!$order->getRelationChildId()) {
                $this->monduFileLogger->info('Trying to cancel Order '.$order->getIncrementId());

                $cancelData = $this->requestFactory->create(RequestFactory::CANCEL)
                    ->process(['orderUid' => $monduId]);

                if (!$cancelData) {
                    $message = 'Mondu: Unexpected error: Order could not be found,' .
                        ' please contact Mondu Support to resolve this issue.';
                    $this->messageManager
                        ->addErrorMessage($message);
                    return;
                }

                $order->addStatusHistoryComment(
                    __('Mondu: The order with the id %1 was successfully canceled.', $monduId)
                );
                $order->save();
                $this->monduFileLogger->info('Cancelled order ', ['orderNumber' => $order->getIncrementId()]);
            }
        } catch (Exception $error) {
            $this->monduFileLogger
                ->info('Failed to cancel Order '.$order->getIncrementId(), ['e' => $error->getMessage()]);
            throw new LocalizedException(__($error->getMessage()));
        }
    }
}
