<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Locale\Resolver;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Address;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\Request\UrlBuilder;
use Mondu\Mondu\Model\Payment\AsyncOrderFields;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class CreateAsyncOrder extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * Reverse mapping: Magento payment code → Mondu API payment_method name.
     */
    private const PAYMENT_METHOD_MAP = [
        'mondu'                      => 'invoice',
        'mondusepa'                  => 'direct_debit',
        'monduinstallment'           => 'installment',
        'monduinstallmentbyinvoice'  => 'installment_by_invoice',
        'mondupaynow'               => 'pay_now',
    ];

    /**
     * @param Curl $curl
     * @param ConfigProvider $configProvider
     * @param MonduFileLogger $monduFileLogger
     * @param OrderHelper $orderHelper
     * @param Resolver $localeResolver
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(
        Curl $curl,
        private readonly ConfigProvider $configProvider,
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
     * Uses POST /api/v1/orders/create_async (returns 202).
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

        $payloadArray = $this->buildPayload($order);
        $payload = json_encode($payloadArray);
        $url = $this->urlBuilder->getCreateAsyncOrderUrl();

        $this->monduFileLogger->info('CreateAsyncOrder: REQUEST', [
            'order_increment_id' => $order->getIncrementId(),
            'url' => $url,
            'payload' => $payloadArray,
        ]);

        $resultJson = $this->sendRequestWithParams('post', $url, $payload);

        $this->monduFileLogger->info('CreateAsyncOrder: RESPONSE', [
            'order_increment_id' => $order->getIncrementId(),
            'http_status' => $this->curl->getStatus(),
            'body' => $resultJson,
        ]);

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu: empty response when creating async order'));
        }

        $result = json_decode($resultJson, true);

        if (isset($result['errors']) || !isset($result['order']['uuid'])) {
            $this->monduFileLogger->error('CreateAsyncOrder: API error', [
                'order_increment_id' => $order->getIncrementId(),
                'http_status' => $this->curl->getStatus(),
                'response' => $result,
            ]);
            throw new LocalizedException(
                __('Mondu: %1', $this->formatApiErrors($result))
            );
        }

        return $result;
    }

    /**
     * Flattens Mondu 422 payload into a single human-readable line.
     *
     * Example payload: {"errors":[{"details":"must be filled","name":"net_term"}],"status":422}
     * → "net_term: must be filled"
     *
     * @param array<mixed> $result
     */
    private function formatApiErrors(array $result): string
    {
        $errors = $result['errors'] ?? null;
        if (!is_array($errors) || $errors === []) {
            return (string) __('error creating async order');
        }

        $parts = [];
        foreach ($errors as $err) {
            if (!is_array($err)) {
                $parts[] = (string) $err;
                continue;
            }
            $name    = $err['name']    ?? '';
            $details = $err['details'] ?? '';
            $friendly = $this->friendlyErrorMessage($name, $details);
            if ($friendly !== null) {
                $parts[] = $friendly;
            } elseif ($name !== '' && $details !== '') {
                $parts[] = sprintf('%s: %s', $name, $details);
            } elseif ($details !== '') {
                $parts[] = (string) $details;
            } elseif ($name !== '') {
                $parts[] = (string) $name;
            }
        }

        return $parts === [] ? (string) __('error creating async order') : implode('; ', $parts);
    }

    private function friendlyErrorMessage(string $name, string $details): ?string
    {
        if (str_contains($details, 'minimum amount')) {
            return (string) __(
                'The order total is below the minimum for this payment method. '
                . 'Please add more products or choose a different payment method.'
            );
        }
        if (str_contains($details, 'maximum amount')) {
            return (string) __(
                'The order total exceeds the maximum for this payment method. '
                . 'Please reduce the order total or choose a different payment method.'
            );
        }
        if ($name === 'payment_method' && str_contains($details, 'not supported')) {
            return (string) __('This payment method is not available for your merchant account.');
        }
        if ($name === 'currency' && str_contains($details, 'not supported')) {
            return (string) __('The order currency is not supported by Mondu.');
        }

        $fieldLabels = [
            'net_term'          => 'Net term (days)',
            'iban'              => 'IBAN',
            'account_holder'    => 'Account holder',
            'company_name'      => 'Company name',
            'email'             => 'Email',
            'first_name'        => 'First name',
            'last_name'         => 'Last name',
            'legal_form'        => 'Legal form',
            'phone'             => 'Phone',
        ];
        $label = $fieldLabels[$name] ?? $name;

        if (str_contains($details, 'must be filled')) {
            return (string) __('%1 is required.', $label);
        }
        if (str_contains($details, 'invalid format') || str_contains($details, 'is invalid')) {
            return (string) __('%1 has an invalid format. Please check and correct the value.', $label);
        }

        return null;
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
        $billing = $order->getBillingAddress();

        $payload = [
            'currency'              => $order->getBaseCurrencyCode(),
            'language'              => $language,
            'external_reference_id' => $order->getIncrementId(),
            'payment_method'        => $this->getMonduPaymentMethod($order),
            'gross_amount_cents'    => (int) round($order->getBaseGrandTotal() * 100),
            'buyer'                 => $this->buildBuyerParams($order),
            'billing_address'       => $this->extractAddressParams($billing),
            'shipping_address'      => $this->extractAddressParams($order->getShippingAddress()),
            'lines'                 => $this->buildLines($order),
            'owners'                => $this->buildOwners($billing),
        ];

        $this->applyPaymentMethodFields($order, $payload);

        return $payload;
    }

    /**
     * Injects payment-method-specific fields collected from the admin order form
     * (stored in payment.additional_information by the DataAssignObserver).
     *
     * @param OrderInterface $order
     * @param array<string,mixed> $payload
     */
    private function applyPaymentMethodFields(OrderInterface $order, array &$payload): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $methodCode = (string) $payment->getMethod();
        $required = AsyncOrderFields::requiredFor($methodCode);
        if ($required === []) {
            return;
        }

        $netTerm      = $payment->getAdditionalInformation(AsyncOrderFields::FIELD_NET_TERM);
        $installments = $payment->getAdditionalInformation(AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS);

        if (in_array(AsyncOrderFields::FIELD_NET_TERM, $required, true) && $netTerm !== null && $netTerm !== '') {
            $payload['net_term'] = (int) $netTerm;
        }
        if (in_array(AsyncOrderFields::FIELD_NUMBER_OF_INSTALLMENTS, $required, true)
            && $installments !== null && $installments !== ''
        ) {
            $payload['number_of_installments'] = (int) $installments;
        }
    }

    /**
     * Maps the Magento payment method code to the Mondu API payment_method name.
     *
     * @param OrderInterface $order
     * @return string
     */
    private function getMonduPaymentMethod(OrderInterface $order): string
    {
        $m2Code = $order->getPayment()?->getMethod() ?? 'mondu';

        return self::PAYMENT_METHOD_MAP[$m2Code] ?? 'invoice';
    }

    /**
     * Builds the `lines` array (required by /orders/create_async).
     *
     * @param OrderInterface $order
     * @return array
     */
    private function buildLines(OrderInterface $order): array
    {
        $lineItems = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $price = (float) $item->getBasePrice();
            if (!$price) {
                continue;
            }

            $variationId = $item->getProductId();
            if ($item->getProductType() === 'configurable' && $item->getChildrenItems()) {
                foreach ($item->getChildrenItems() as $child) {
                    $variationId = $child->getProductId();
                }
            }

            $lineItems[] = [
                'title'                    => $item->getName(),
                'net_price_per_item_cents' => (int) round($price * 100),
                'variation_id'             => $variationId,
                'item_type'                => $item->getIsVirtual() ? 'VIRTUAL' : 'PHYSICAL',
                'external_reference_id'    => $variationId . '-' . $item->getItemId(),
                'quantity'                 => (int) $item->getQtyOrdered(),
                'product_sku'              => $item->getSku(),
                'product_id'               => $item->getProductId(),
            ];
        }

        $shippingAmount = (float) $order->getBaseShippingAmount();
        $taxAmount = (float) $order->getBaseTaxAmount();

        return [
            [
                'shipping_price_cents' => $shippingAmount ? (int) round($shippingAmount * 100) : 0,
                'tax_cents'            => (int) round($taxAmount * 100),
                'line_items'           => $lineItems,
            ],
        ];
    }

    /**
     * Builds the `owners` array (required by /orders/create_async).
     *
     * @param Address|null $billing
     * @return array
     */
    private function buildOwners(?Address $billing): array
    {
        if (!$billing) {
            return [];
        }

        return [
            [
                'first_name' => $billing->getFirstname() ?? '',
                'last_name'  => $billing->getLastname() ?? '',
            ],
        ];
    }

    /**
     * Builds buyer params from order data.
     *
     * @param OrderInterface $order
     * @return array
     */
    private function buildBuyerParams(OrderInterface $order): array
    {
        $customerId = $order->getCustomerId();
        $billing    = $order->getBillingAddress();

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

        $this->applyBuyerPaymentFields($order, $params);

        return $params;
    }

    /**
     * Injects buyer.* fields collected from the admin order form
     * (legal_form, iban, account_holder) when required for the selected method.
     *
     * @param OrderInterface $order
     * @param array<string,mixed> $params
     */
    private function applyBuyerPaymentFields(OrderInterface $order, array &$params): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }
        $methodCode = (string) $payment->getMethod();
        $required = AsyncOrderFields::requiredFor($methodCode);
        if ($required === []) {
            return;
        }

        $legalForm     = $payment->getAdditionalInformation(AsyncOrderFields::FIELD_LEGAL_FORM);
        $iban          = $payment->getAdditionalInformation(AsyncOrderFields::FIELD_IBAN);
        $accountHolder = $payment->getAdditionalInformation(AsyncOrderFields::FIELD_ACCOUNT_HOLDER);

        if (in_array(AsyncOrderFields::FIELD_LEGAL_FORM, $required, true) && $legalForm) {
            $params['legal_form'] = (string) $legalForm;
        }
        if (in_array(AsyncOrderFields::FIELD_IBAN, $required, true) && $iban) {
            $params['iban'] = preg_replace('/\s+/', '', (string) $iban);
        }
        if (in_array(AsyncOrderFields::FIELD_ACCOUNT_HOLDER, $required, true) && $accountHolder) {
            $params['account_holder'] = (string) $accountHolder;
        }
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
}
