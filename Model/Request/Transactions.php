<?php
namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Mondu\Mondu\Helpers\BuyerParams\BuyerParamsInterface;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Ui\ConfigProvider;
use Magento\Framework\UrlInterface;
use Magento\Framework\Locale\Resolver;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;

class Transactions extends CommonRequest implements RequestInterface
{
    /**
     * @var CheckoutSession
     */
    protected $_checkoutSession;

    /**
     * @var CartTotalRepository
     */
    protected $_cartTotalRepository;

    /**
     * @var ConfigProvider
     */
    protected $_configProvider;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var string
     */
    private $fallbackEmail;

    /**
     * @var OrderHelper
     */
    private $orderHelper;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * @var BuyerParamsInterface
     */
    private $buyerParams;

    /**
     * @var Resolver
     */
    private $store;

    /**
     * @var MonduFileLogger
     */
    private $monduFileLogger;

    /**
     * @param Curl $curl
     * @param CartTotalRepository $cartTotalRepository
     * @param CheckoutSession $checkoutSession
     * @param ConfigProvider $configProvider
     * @param OrderHelper $orderHelper
     * @param UrlInterface $urlBuilder
     * @param BuyerParamsInterface $buyerParams
     */
    public function __construct(
        Curl $curl,
        CartTotalRepository $cartTotalRepository,
        CheckoutSession $checkoutSession,
        ConfigProvider $configProvider,
        OrderHelper $orderHelper,
        UrlInterface $urlBuilder,
        BuyerParamsInterface $buyerParams,
        Resolver $store,
        MonduFileLogger $monduFileLogger
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_cartTotalRepository = $cartTotalRepository;
        $this->_configProvider = $configProvider;
        $this->curl = $curl;
        $this->orderHelper = $orderHelper;
        $this->urlBuilder = $urlBuilder;
        $this->buyerParams = $buyerParams;
        $this->store = $store;
        $this->monduFileLogger = $monduFileLogger;
    }

    /**
     * Request
     *
     * @param array $_params
     * @return array
     */
    public function request($_params = []): array
    {
        try {
            if ($_params['email']) {
                $this->fallbackEmail = $_params['email'];
            }
            $params = $this->getRequestParams();

            if (in_array(
                $_params['payment_method'],
                [PaymentMethod::DIRECT_DEBIT, PaymentMethod::INSTALLMENT, PaymentMethod::INSTALLMENT_BY_INVOICE])
            ) {
                $params['payment_method'] = $_params['payment_method'];
            }

            $params = json_encode($params);

            $url = $this->_configProvider->getApiUrl('orders');

            $this->curl->addHeader('X-Mondu-User-Agent', $_params['user-agent']);

            $result = $this->sendRequestWithParams('post', $url, $params);
            $data = json_decode($result, true);
            $this->_checkoutSession->setMonduid($data['order']['uuid'] ?? null);

            if (!isset($data['order']['uuid'])) {
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
            $this->monduFileLogger->error('Error while creating an order', [
                'message' => $e->getMessage(),
                'trace' => $e->getTrace()
            ]);
            return [
                'error' => 1,
                'body' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get Request Params from
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function getRequestParams()
    {
        $quote = $this->_checkoutSession->getQuote();
        $quote->collectTotals();

        $quoteTotals = $this->_cartTotalRepository->get($quote->getId());

        $discountAmount = $quoteTotals->getDiscountAmount();

        $successUrl = $this->urlBuilder->getUrl('mondu/payment_checkout/success');
        $cancelUrl = $this->urlBuilder->getUrl('mondu/payment_checkout/cancel');
        $declinedUrl = $this->urlBuilder->getUrl('mondu/payment_checkout/decline');

        $locale = $this->store->getLocale();
        $language = $locale ? strstr($locale, '_', true) : 'de';

        $order = [
            'language' => $language,
            'currency' => $quote->getBaseCurrencyCode(),
            'state_flow' => ConfigProvider::AUTHORIZATION_STATE_FLOW,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'declined_url' => $declinedUrl,
            'total_discount_cents' => abs($discountAmount) * 100,
            'buyer' => $this->getBuyerParams($quote),
            'external_reference_id' => uniqid('M2_'),
            'billing_address' => $this->getBillingAddressParams($quote),
            'shipping_address' => $this->getShippingAddressParams($quote)
        ];

        return $this->orderHelper->addLinesOrGrossAmountToOrder($quote, $quoteTotals->getBaseGrandTotal(), $order);
    }

    /**
     * Get Buyer params
     *
     * @param Quote $quote
     * @return array
     */
    private function getBuyerParams(Quote $quote): array
    {
        $params = [];
        if (($billing = $quote->getBillingAddress()) !== null) {
            $params = [
                'is_registered' => (bool) $quote->getCustomer()->getId(),
                'external_reference_id' => $quote->getCustomerId() ? (string) $quote->getCustomerId() : null,
                'email' => $billing->getEmail() ??
                    $quote->getShippingAddress()->getEmail() ??
                    $quote->getCustomerEmail() ??
                    $this->fallbackEmail,
                'company_name' => $billing->getCompany(),
                'first_name' => $billing->getFirstname(),
                'last_name' => $billing->getLastname(),
                'phone' => $billing->getTelephone()
            ];

            $params = $this->buyerParams->getBuyerParams($params, $quote);
        }
        return $params;
    }

    /**
     * Get billing address params
     *
     * @param Quote $quote
     * @return array
     */
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

    /**
     * Get shipping address params
     *
     * @param Quote $quote
     * @return array
     */
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
}
