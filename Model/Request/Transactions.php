<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Exception;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Locale\Resolver;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Mondu\Mondu\Helpers\BuyerParams\BuyerParamsInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Transactions extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var string
     */
    private string $fallbackEmail;

    /**
     * @param Curl $curl
     * @param BuyerParamsInterface $buyerParams
     * @param CartTotalRepository $cartTotalRepository
     * @param CheckoutSession $checkoutSession
     * @param UrlBuilder $monduUrlBuilder
     * @param MonduFileLogger $monduFileLogger
     * @param OrderHelper $orderHelper
     * @param Resolver $store
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        Curl $curl,
        private readonly BuyerParamsInterface $buyerParams,
        private readonly CartTotalRepository $cartTotalRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly UrlBuilder $monduUrlBuilder,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly OrderHelper $orderHelper,
        private readonly Resolver $store,
        private readonly UrlInterface $urlBuilder,
    ) {
        $this->curl = $curl;
    }

    /**
     * Sends a request to Mondu to create a transaction based on the current quote.
     *
     * @param array $params
     * @return array
     */
    public function request($params = []): array
    {
        try {
            $this->fallbackEmail = $params['email'] ?? '';
            $requestParams = $this->getRequestParams();
            $monduMethods = [
                PaymentMethod::DIRECT_DEBIT,
                PaymentMethod::INSTALLMENT,
                PaymentMethod::INSTALLMENT_BY_INVOICE,
            ];

            if (in_array($params['payment_method'], $monduMethods, true)) {
                $requestParams['payment_method'] = $params['payment_method'];
            }

            $requestParams = json_encode($requestParams);
            $this->curl->addHeader('X-Mondu-User-Agent', $params['user-agent']);

            $result = $this->sendRequestWithParams(
                'post',
                $this->monduUrlBuilder->getOrdersUrl(),
                $requestParams
            );
            $data = json_decode($result, true);
            $this->checkoutSession->setMonduid($data['order']['uuid'] ?? null);

            if (!isset($data['order']['uuid'])) {
                return [
                    'error' => 1,
                    'body' => json_decode($result, true),
                    'message' => __('Error placing an order Please try again later.'),
                ];
            }

            return [
                'error' => 0,
                'body' => json_decode($result, true),
                'message' => __('Success'),
            ];
        } catch (Exception $e) {
            $this->monduFileLogger->error('Error while creating an order', [
                'message' => $e->getMessage(),
                'trace' => $e->getTrace(),
            ]);
            return [
                'error' => 1,
                'body' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Returns request payload from Magento quote and Mondu requirements.
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @return array
     */
    protected function getRequestParams(): array
    {
        $quote = $this->checkoutSession->getQuote();
        $quote->collectTotals();

        $quoteTotals = $this->cartTotalRepository->get($quote->getId());

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
            'shipping_address' => $this->getShippingAddressParams($quote),
        ];

        return $this->orderHelper->addLinesOrGrossAmountToOrder($quote, $quoteTotals->getBaseGrandTotal(), $order);
    }

    /**
     * Returns buyer information from the quote billing address and customer data.
     *
     * @param CartInterface $quote
     * @return array
     */
    private function getBuyerParams(CartInterface $quote): array
    {
        $billing = $quote->getBillingAddress();
        if (!$billing) {
            return [];
        }

        $params = [
            'is_registered' => (bool) $quote->getCustomer()->getId(),
            'external_reference_id' => $quote->getCustomerId() ? (string) $quote->getCustomerId() : null,
            'email' => $billing->getEmail()
                ?? $quote->getShippingAddress()->getEmail()
                ?? $quote->getCustomerEmail()
                ?? $this->fallbackEmail,
            'company_name' => $billing->getCompany(),
            'first_name' => $billing->getFirstname(),
            'last_name' => $billing->getLastname(),
            'phone' => $billing->getTelephone(),
        ];

        return $this->buyerParams->getBuyerParams($params, $quote);
    }

    /**
     * Extracts billing address details from the quote for Mondu request.
     *
     * @param CartInterface $quote
     * @return array
     */
    public function getBillingAddressParams(CartInterface $quote): array
    {
        return $this->extractAddressParams($quote->getBillingAddress());
    }

    /**
     * Extracts shipping address details from the quote for Mondu request.
     *
     * @param CartInterface $quote
     * @return array
     */
    public function getShippingAddressParams(CartInterface $quote): array
    {
        return $this->extractAddressParams($quote->getShippingAddress());
    }

    /**
     * Extracts address fields into Mondu format.
     *
     * @param AddressInterface|null $address
     * @return array
     */
    public function extractAddressParams(?AddressInterface $address): array
    {
        if (!$address) {
            return [];
        }

        $street = (array) $address->getStreet();
        $line1 = (string) array_shift($street);
        if ($address->getStreetNumber()) {
            $line1 .= ', ' . $address->getStreetNumber();
        }

        $params = [
            'country_code' => $address->getCountryId(),
            'city' => $address->getCity(),
            'zip_code' => $address->getPostcode(),
            'address_line1' => $line1,
            'address_line2' => (string) implode(' ', $street),
        ];

        if ($address->getRegion()) {
            $params['state'] = (string) $address->getRegion();
        }

        return $params;
    }
}
