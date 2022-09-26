<?php
namespace Mondu\Mondu\Model\Request;

use \Magento\Framework\HTTP\Client\Curl;
use \Magento\Quote\Model\Cart\CartTotalRepository;
use \Magento\Checkout\Model\Session as CheckoutSession;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Magento\Quote\Model\Quote;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Transactions extends CommonRequest implements RequestInterface
{
    protected $_checkoutSession;
    protected $_cartTotalRepository;
    protected $_config;
    protected $_scopeConfigInterface;
    protected $_configProvider;

    private $curl;
    private $fallbackEmail;
    public function __construct(
        Curl $curl,
        CartTotalRepository $cartTotalRepository,
        CheckoutSession $checkoutSession,
        ScopeConfigInterface $scopeConfigInterface,
        ConfigProvider $configProvider
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_cartTotalRepository = $cartTotalRepository;
        $this->_scopeConfigInterface = $scopeConfigInterface;
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
    }

    public function process($_params = []) {
        try {
            if(@$_params['email']) {
                $this->fallbackEmail = $_params['email'];
            }
            $params = $this->getRequestParams();

            if($_params['payment_method'] === 'direct_debit' || $_params['payment_method'] === 'installment') {
                $params['payment_method'] = $_params['payment_method'];
            }

            $params = json_encode($params);

            $api_token = $this->_scopeConfigInterface->getValue('payment/mondu/mondu_key');
            $url = $this->_configProvider->getApiUrl('orders');

            $headers = $this->getHeaders($api_token);
            $headers['X-Mondu-User-Agent'] = $_params['user-agent'];

            $this->curl->setHeaders($headers);
            $this->curl->post($url, $params);

            $result = $this->curl->getBody();
            $data = json_decode($result, true);
            $this->_checkoutSession->setMonduid(@$data['order']['uuid']);
            if(!@$data['order']['uuid']) {
                return [
                    'error' => 1,
                    'body' => json_decode($result, true),
                    'message' => __('Error placing an order, please try again later')
                ];
            } else {
                return [
                    'error' => 0,
                    'body' => json_decode($result, true),
                    'message' => __('Success')
                ];
            }
        } catch(\Exception $e) {
            return [
                'error' => 1,
                'body' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    protected function getRequestParams() {
        $quote = $this->_checkoutSession->getQuote();
        $quote->collectTotals();
        $requiresShipping = $quote->getShippingAddress() !== null ? 1 : 0;

        $quoteTotals = $this->_cartTotalRepository->get($quote->getId());
        $discountAmount = $quoteTotals->getDiscountAmount();
        return [
            'currency' => $quote->getBaseCurrencyCode(),
            'total_discount_cents' => abs($discountAmount) * 100,
            'buyer' => $this->getBuyerParams($quote),
            'external_reference_id' => $this->getExternalReferenceId($quote),
            'lines' => $this->getLinesParams($quote),
            'billing_address' => $this->getBillingAddressParams($quote),
            'shipping_address' => $this->getShippingAddressParams($quote)
        ];
    }

    private function getBuyerParams(Quote $quote) {
        $params = [];
        if (($billing = $quote->getBillingAddress()) !== null) {
            $params = [
                'is_registered' => $quote->getCustomer()->getId() ? true : false,
                'email' => $billing->getEmail() ?? $quote->getShippingAddress()->getEmail() ?? $quote->getCustomerEmail() ?? $this->fallbackEmail,
                'company_name' => $billing->getCompany(),
                'first_name' => $billing->getFirstname(),
                'last_name' => $billing->getLastname(),
                'phone' => $billing->getTelephone()
            ];
        }
        return $params;
    }

    private function getLinesParams(Quote $quote) {
        $shippingTotal = $this->_cartTotalRepository->get($quote->getId())->getBaseShippingAmount();
        $totalTax = round($quote->getShippingAddress()->getBaseTaxAmount(), 2);
        $taxCompensation = $quote->getShippingAddress()->getBaseDiscountTaxCompensationAmount() ?? 0;
        $totalTax = round($totalTax + $taxCompensation, 2);
        $lineItems = [];

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $variationId = $quoteItem->getProductId();

            $price = (float) $quoteItem->getBasePrice();
            if (!$price) {
                continue;
            }

            $quoteItem->getProduct()->load($quoteItem->getProductId());

            if ($quoteItem->getProductType() === 'configurable' && $quoteItem->getHasChildren()) {
                foreach ($quoteItem->getChildren() as $child) {
                    $variationId = $child->getProductId();
                    $child->getProduct()->load($child->getProductId());
                    continue;
                }
            }

            $lineItems[] = [
                'title' => $quoteItem->getName(),
                'net_price_per_item_cents' => $price * 100,
                'variation_id' => $variationId,
                'item_type' => $quoteItem->getIsVirtual() ? 'VIRTUAL' : 'PHYSICAL',
                'external_reference_id' => $variationId,
                'quantity' => $quoteItem->getQty(),
                'product_sku' => $quoteItem->getSku(),
                'product_id' => $quoteItem->getProductId()
            ];
        }

        return [
            [
                'shipping_price_cents' => $shippingTotal * 100,
                'tax_cents' => $totalTax * 100,
                'line_items' => $lineItems
            ]
        ];
    }

    private function getBillingAddressParams(Quote $quote) {
        $params = [];

        if (($billing = $quote->getBillingAddress()) !== null) {
            $address = (array) $billing->getStreet();
            $line1 = (string) array_shift($address);
            if($billing->getStreetNumber()) {
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
            if($billing->getRegion()) {
                $params['state'] = (string) $billing->getRegion();
            }
        }

        return $params;
    }

    private function getShippingAddressParams(Quote $quote) {
        $params = [];

        if (($shipping = $quote->getShippingAddress()) !== null) {
            $address = (array) $shipping->getStreet();
            $line1 = (string) array_shift($address);
            if($shipping->getStreetNumber()) {
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

            if($shipping->getRegion()) {
                $params['state'] = (string) $shipping->getRegion();
            }
        }

        return $params;
    }

    public function getExternalReferenceId(Quote $quote) {
        $reservedOrderId = $quote->getReservedOrderId();
        if (!$reservedOrderId) {
            $quote->reserveOrderId()->save();
            $reservedOrderId = $quote->getReservedOrderId();
        }
        return $reservedOrderId;
    }
}
