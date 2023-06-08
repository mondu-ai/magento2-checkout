<?php
namespace Mondu\Mondu\Helpers;

use Magento\Framework\App\CacheInterface;
use Mondu\Mondu\Model\Request\Factory;

class PaymentMethod
{
    public const PAYMENTS = ['mondu', 'mondusepa', 'monduinstallment'];

    public const LABELS = [
        'mondusepa' => 'SEPA Direct Debit',
        'monduinstallment' => 'Installment',
        'mondu' => 'Rechnungskauf'
    ];

    public const MAPPING = [
        'direct_debit' => 'mondusepa',
        'invoice' => 'mondu',
        'installment' => 'monduinstallment'
    ];
    /**
     * @var Factory
     */
    private $requestFactory;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @param Factory $requestFactory
     * @param CacheInterface $cache
     */
    public function __construct(
        Factory $requestFactory,
        CacheInterface $cache
    ) {
        $this->requestFactory = $requestFactory;
        $this->cache = $cache;
    }

    /**
     * GetPayments
     *
     * @return string[]
     */
    public function getPayments()
    {
        return self::PAYMENTS;
    }

    /**
     * GetAllowed
     *
     * @param float|int|null $storeId
     * @return array
     */
    public function getAllowed($storeId = null)
    {
        try {
            if ($result = $this->cache->load('mondu_payment_methods_'.$storeId)) {
                return json_decode($result, true);
            }
            $paymentMethods = $this->requestFactory->create(Factory::PAYMENT_METHODS)->process();
            $result = [];
            foreach ($paymentMethods as $value) {
                $result[] = self::MAPPING[$value['identifier']] ?? '';
            }
            $this->cache->save(json_encode($result), 'mondu_payment_methods_'.$storeId, [], 3600);
            return $result;
        } catch (\Exception $e) {
            $this->cache->save(json_encode([]), 'mondu_payment_methods_'.$storeId, [], 3600);
            return [];
        }
    }

    /**
     * ResetAllowedCache
     *
     * @return void
     */
    public function resetAllowedCache()
    {
        $this->cache->remove('mondu_payment_methods');
    }

    /**
     * IsMondu
     *
     * @param mixed $method
     * @return bool
     */
    public function isMondu($method): bool
    {
        $code = $method->getCode() ?? $method->getMethod();

        return in_array($code, self::PAYMENTS);
    }

    /**
     * GetCode
     *
     * @param mixed $method
     * @return mixed
     */
    public function getCode($method)
    {
        return $method->getCode() ?? $method->getMethod();
    }

    /**
     * GetLabel
     *
     * @param string $code
     * @return string|null
     */
    public function getLabel($code)
    {
        return self::LABELS[$code] ?? null;
    }
}
