<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Locale\Resolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Address;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class CreateAsyncOrder extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     * @param CustomerRepositoryInterface $customerRepository
     * @param MonduFileLogger $monduFileLogger
     * @param OrderHelper $orderHelper
     * @param Resolver $localeResolver
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(
        Curl $curl,
        private readonly ConfigProvider $configProvider,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly OrderHelper $orderHelper,
        private readonly Resolver $localeResolver,
        private readonly UrlBuilder $urlBuilder,
    ) {
        $this->curl = $curl;
    }

    /**
     * Creates an async order in Mondu on behalf of the buyer.
     *
     * @param array $params  ['order' => OrderInterface]
     * @throws LocalizedException
     * @return array
     */
    protected function request($params): array
    {
        /** @var OrderInterface $order */
        $order = $params['order'] ?? null;

        if (!$order) {
            throw new LocalizedException(__('CreateAsyncOrder: order param is required'));
        }

        $payload = json_encode($this->buildPayload($order));

        $this->monduFileLogger->info('CreateAsyncOrder: sending request', [
            'order_increment_id' => $order->getIncrementId(),
        ]);

        $resultJson = $this->sendRequestWithParams('post', $this->urlBuilder->getOrdersUrl(), $payload);

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu: empty response when creating async order'));
        }

        $result = json_decode($resultJson, true);

        if (isset($result['errors']) || !isset($result['order']['uuid'])) {
            $this->monduFileLogger->error('CreateAsyncOrder: API error', ['response' => $result]);
            throw new LocalizedException(__('Mondu: error creating async order'));
        }

        return $result;
    }

    /**
     * Builds the full async order payload from the M2 order.
     *
     * @param OrderInterface $order
     * @throws LocalizedException
     * @return array
     */
    private function buildPayload(OrderInterface $order): array
    {
        $locale = $this->localeResolver->getLocale();
        $language = $locale ? strstr($locale, '_', true) : 'de';

        $payload = [
            'state_flow'           => 'async',
            'currency'             => $order->getBaseCurrencyCode(),
            'language'             => $language,
            'external_reference_id' => uniqid('M2_ASYNC_'),
            'gross_amount_cents'   => (int) round($order->getBaseGrandTotal() * 100),
            'buyer'                => $this->buildBuyerParams($order),
            'billing_address'      => $this->extractAddressParams($order->getBillingAddress()),
            'shipping_address'     => $this->extractAddressParams($order->getShippingAddress()),
        ];

        $payload['amount'] = [
            'net_price_cents'    => (int) round($order->getBaseSubtotal() * 100),
            'tax_cents'          => (int) round($order->getBaseTaxAmount() * 100),
            'gross_amount_cents' => (int) round($order->getBaseGrandTotal() * 100),
        ];

        if ($this->configProvider->sendLines()) {
            $payload['line_items'] = $this->buildLineItems($order);
        }

        return $payload;
    }

    /**
     * Builds buyer params: uses stored buyer_uuid when available, otherwise sends full details.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function buildBuyerParams(OrderInterface $order): array
    {
        $customerId = $order->getCustomerId();

        if ($customerId) {
            try {
                $customer = $this->customerRepository->getById((int) $customerId);
                $buyerUuidAttr = $customer->getCustomAttribute('mondu_buyer_uuid');
                $buyerUuid = $buyerUuidAttr ? (string) $buyerUuidAttr->getValue() : null;

                if ($buyerUuid) {
                    return ['uuid' => $buyerUuid];
                }
            } catch (\Exception $e) {
                $this->monduFileLogger->warning('CreateAsyncOrder: could not load customer for buyer_uuid', [
                    'customer_id' => $customerId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $billing = $order->getBillingAddress();

        $params = [
            'is_registered' => (bool) $customerId,
            'email'         => $order->getCustomerEmail() ?? ($billing ? $billing->getEmail() : ''),
            'company_name'  => $billing ? $billing->getCompany() : '',
            'first_name'    => $order->getCustomerFirstname() ?? ($billing ? $billing->getFirstname() : ''),
            'last_name'     => $order->getCustomerLastname() ?? ($billing ? $billing->getLastname() : ''),
            'phone'         => $billing ? $billing->getTelephone() : '',
        ];

        if ($customerId) {
            $params['external_reference_id'] = (string) $customerId;
        }

        if ($billing) {
            $vatId = $billing->getVatId();
            if ($vatId !== null && $vatId !== '') {
                $params['vat_number'] = (string) $vatId;
            }
        }

        return $params;
    }

    /**
     * Extracts address fields from an order address into Mondu format.
     *
     * @param Address|null $address
     * @return array
     */
    private function extractAddressParams(?Address $address): array
    {
        if (!$address) {
            return [];
        }

        $street = (array) $address->getStreet();
        $line1 = (string) array_shift($street);

        return [
            'country_code'  => $address->getCountryId(),
            'city'          => $address->getCity(),
            'zip_code'      => $address->getPostcode(),
            'address_line1' => $line1,
            'address_line2' => implode(' ', $street),
        ];
    }

    /**
     * Builds line items from order items.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function buildLineItems(OrderInterface $order): array
    {
        $lineItems = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $price = (float) $item->getBasePrice();
            if (!$price) {
                continue;
            }

            $lineItems[] = [
                'title'                  => $item->getName(),
                'net_price_per_item_cents' => (int) round($price * 100),
                'variation_id'           => $item->getProductId(),
                'item_type'              => $item->getIsVirtual() ? 'VIRTUAL' : 'PHYSICAL',
                'external_reference_id'  => $item->getProductId() . '-' . $item->getItemId(),
                'quantity'               => (int) $item->getQtyOrdered(),
                'product_sku'            => $item->getSku(),
                'product_id'             => $item->getProductId(),
            ];
        }

        return $lineItems;
    }
}
