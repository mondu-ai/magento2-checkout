<?php

declare(strict_types=1);

namespace Mondu\Mondu\Helpers;

use Exception;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Mondu\Mondu\Model\Request\Factory as RequestFactory;

class PaymentMethod
{
    public const DIRECT_DEBIT = 'direct_debit';
    public const INSTALLMENT = 'installment';
    public const INSTALLMENT_BY_INVOICE = 'installment_by_invoice';
    public const PAY_NOW = 'pay_now';

    public const PAYMENTS = ['mondu', 'mondusepa', 'monduinstallment', 'monduinstallmentbyinvoice', 'mondupaynow'];

    public const LABELS = [
        'mondu' => 'Rechnungskauf',
        'mondusepa' => 'SEPA Direct Debit',
        'monduinstallment' => 'Installment',
        'monduinstallmentbyinvoice' => 'Installment By Invoice',
        'mondupaynow' => 'Pay Now',
    ];

    public const MAPPING = [
        'invoice' => 'mondu',
        self::DIRECT_DEBIT => 'mondusepa',
        self::INSTALLMENT => 'monduinstallment',
        self::INSTALLMENT_BY_INVOICE => 'monduinstallmentbyinvoice',
        self::PAY_NOW => 'mondupaynow',
    ];
    private const CACHE_KEY_PREFIX = 'mondu_payment_methods_';
    private const CACHE_LIFETIME = 3600;

    /**
     * @param CacheInterface $cache
     * @param RequestFactory $requestFactory
     * @param SerializerInterface $serializer
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly RequestFactory $requestFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * Returns all available Mondu payment method codes.
     *
     * @return string[]
     */
    public function getPayments(): array
    {
        return self::PAYMENTS;
    }

    /**
     * Returns allowed Mondu payment methods for the specified store.
     *
     * @param int $storeId
     * @return array
     */
    public function getAllowed(int $storeId): array
    {
        $cacheKey = self::CACHE_KEY_PREFIX . $storeId;

        try {
            if ($result = $this->cache->load($cacheKey)) {
                return $this->serializer->unserialize($result);
            }

            $paymentMethods = $this->requestFactory->create(RequestFactory::PAYMENT_METHODS)->process();
            $result = [];
            foreach ($paymentMethods as $value) {
                $result[] = self::MAPPING[$value['identifier']] ?? '';
            }

            $this->cache->save($this->serializer->serialize($result), $cacheKey, [], self::CACHE_LIFETIME);
            return $result;
        } catch (Exception $e) {
            $this->cache->save($this->serializer->serialize([]), $cacheKey, [], self::CACHE_LIFETIME);
            return [];
        }
    }

    /**
     * Removes Mondu allowed methods cache.
     *
     * @return void
     */
    public function resetAllowedCache(): void
    {
        $this->cache->remove('mondu_payment_methods');
    }

    /**
     * Checks if the payment method belongs to Mondu.
     *
     * @param OrderPaymentInterface $method
     * @return bool
     */
    public function isMondu(OrderPaymentInterface $method): bool
    {
        $code = $method->getCode() ?? $method->getMethod();

        return in_array($code, self::PAYMENTS, true);
    }

    /**
     * Returns payment method code from the given instance.
     *
     * @param OrderPaymentInterface $method
     * @return string
     */
    public function getCode(OrderPaymentInterface $method): string
    {
        return $method->getCode() ?? $method->getMethod();
    }

    /**
     * Returns Mondu label for the specified method code.
     *
     * @param string $code
     * @return string|null
     */
    public function getLabel(string $code): ?string
    {
        return self::LABELS[$code] ?? null;
    }
}
