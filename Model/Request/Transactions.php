<?php
namespace Mondu\Mondu\Model\Request;

use \Magento\Framework\HTTP\Client\Curl;
use \Magento\Quote\Model\Cart\CartTotalRepository;
use \Magento\Checkout\Model\Session as CheckoutSession;
use \Magento\Quote\Model\Quote;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Transactions extends CommonRequest implements RequestInterface
{
    protected $_checkoutSession;
    protected $_cartTotalRepository;
    protected $_config;
    protected $_configProvider;

    protected $curl;
    private $fallbackEmail;
    private $orderHelper;

    /**
     * @param Curl $curl
     * @param CartTotalRepository $cartTotalRepository
     * @param CheckoutSession $checkoutSession
     * @param ConfigProvider $configProvider
     * @param OrderHelper $orderHelper
     */
    public function __construct(
        Curl $curl,
        CartTotalRepository $cartTotalRepository,
        CheckoutSession $checkoutSession,
        ConfigProvider $configProvider,
        OrderHelper $orderHelper
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_cartTotalRepository = $cartTotalRepository;
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
        $this->orderHelper = $orderHelper;
    }

    public function request($_params = []): array
    {
        try {
            if (@$_params['email']) {
                $this->fallbackEmail = $_params['email'];
            }
            $params = $this->getRequestParams();

            if ($_params['payment_method'] === 'direct_debit' || $_params['payment_method'] === 'installment') {
                $params['payment_method'] = $_params['payment_method'];
            }

            $params = json_encode($params);

            $url = $this->_configProvider->getApiUrl('orders');

            $this->curl->addHeader('X-Mondu-User-Agent', $_params['user-agent']);

            $result = $this->sendRequestWithParams('post', $url, $params);
            $data = json_decode($result, true);
            $this->_checkoutSession->setMonduid(@$data['order']['uuid']);

            if (!@$data['order']['uuid']) {
                return [
                    'error' => 1,
                    'body' => json_decode($result, true),
                    'message' => __('Error placing an order Please try again later.')
                ];
            } else {
                return [
                    'error' => 0,
                    'body' => json_decode($result, true),
                    'message' => __('Success')
                ];
            }
        } catch (\Exception $e) {
            return [
                'error' => 1,
                'body' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function getRequestParams()
    {
        $quote = $this->_checkoutSession->getQuote();
        $quote->collectTotals();
        $requiresShipping = $quote->getShippingAddress() !== null ? 1 : 0;

        $quoteTotals = $this->_cartTotalRepository->get($quote->getId());

        $discountAmount = $quoteTotals->getDiscountAmount();

        $order = [
            'currency' => $quote->getBaseCurrencyCode(),
            'total_discount_cents' => abs($discountAmount) * 100,
            'buyer' => $this->getBuyerParams($quote),
            'external_reference_id' => $this->getExternalReferenceId($quote),
            'billing_address' => $this->getBillingAddressParams($quote),
            'shipping_address' => $this->getShippingAddressParams($quote)
        ];

        return $this->orderHelper->addLinesOrGrossAmountToOrder($quote, $quoteTotals->getBaseGrandTotal(), $order);
    }

    private function getBuyerParams(Quote $quote): array
    {
        $params = [];
        if (($billing = $quote->getBillingAddress()) !== null) {
            $params = [
                'is_registered' => (bool) $quote->getCustomer()->getId(),
                'email' => $billing->getEmail() ?? $quote->getShippingAddress()->getEmail() ?? $quote->getCustomerEmail() ?? $this->fallbackEmail,
                'company_name' => $billing->getCompany(),
                'first_name' => $billing->getFirstname(),
                'last_name' => $billing->getLastname(),
                'phone' => $billing->getTelephone()
            ];
        }
        return $params;
    }

    private function getBillingAddressParams(Quote $quote): array
    {
        $params = [];

        if (($billing = $quote->getBillingAddress()) !== null) {
            $address = (array) $billing->getStreet();
            $line1 = (string) array_shift($address);
            if ($billing->getStreetNumber()) {
                $line1 .= ', '. $billing->getStreetNumber();
            }
            $line2 = (string) implode(' ', $address);
            $params = [
                'country_code' => $billing->getCountryId(),
                'city' => $billing->getCity(),
                'zip_code' => $billing->getPostcode(),
                'address_line1' => $line1,
                'address_line2' => $line2,
            ];
            if ($billing->getRegion()) {
                $params['state'] = (string) $billing->getRegion();
            }
        }

        return $params;
    }

    private function getShippingAddressParams(Quote $quote): array
    {
        $params = [];

        if (($shipping = $quote->getShippingAddress()) !== null) {
            $address = (array) $shipping->getStreet();
            $line1 = (string) array_shift($address);
            if ($shipping->getStreetNumber()) {
                $line1 .= ', '. $shipping->getStreetNumber();
            }
            $line2 = (string) implode(' ', $address);
            $params = [
                'country_code' => $shipping->getCountryId(),
                'city' => $shipping->getCity(),
                'zip_code' => $shipping->getPostcode(),
                'address_line1' => $line1,
                'address_line2' => $line2,
            ];

            if ($shipping->getRegion()) {
                $params['state'] = (string) $shipping->getRegion();
            }
        }

        return $params;
    }

    /**
     * @param Quote $quote
     * @return mixed|string|null
     * @throws \Exception
     */
    public function getExternalReferenceId(Quote $quote)
    {
        $reservedOrderId = $quote->getReservedOrderId();
        if (!$reservedOrderId) {
            $quote->reserveOrderId()->save();
            $reservedOrderId = $quote->getReservedOrderId();
        }
        return $reservedOrderId;
    }
}
