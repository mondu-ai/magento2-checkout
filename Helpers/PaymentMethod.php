<?php
namespace Mondu\Mondu\Helpers;

use Magento\Framework\App\CacheInterface;
use Mondu\Mondu\Model\Request\Factory;

class PaymentMethod {
    const PAYMENTS = ['mondu', 'mondusepa', 'monduinstallment'];

    const LABELS = [
        'mondusepa' => 'SEPA Direct Debit',
        'monduinstallment' => 'Installment',
        'mondu' => 'Rechnungskauf'
    ];

    const MAPPING = [
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

    public function __construct(
        Factory $requestFactory,
        CacheInterface $cache
    ) {
        $this->requestFactory = $requestFactory;
        $this->cache = $cache;
    }

    public function getPayments() {
        return self::PAYMENTS;
    }

    public function getAllowed() {
        try {
            if($result = $this->cache->load('mondu_payment_methods')) {
                return json_decode($result, true);
            }
            $paymentMethods = $this->requestFactory->create(Factory::PAYMENT_METHODS)->process();
            $result = [];
            foreach ($paymentMethods as $value) {
                $result[] = @self::MAPPING[$value['identifier']] ?? '';
            }
            $this->cache->save(json_encode($result), 'mondu_payment_methods', [], 3600);
            return $result;
        } catch (\Exception $e) {
            $this->cache->save(json_encode([]), 'mondu_payment_methods', [], 3600);
            return [];
        }
    }

    public function resetAllowedCache() {
        $this->cache->remove('mondu_payment_methods');
    }

    public function isMondu($method): bool
    {
        $code = $method->getCode() ?? $method->getMethod();

        return in_array($code, self::PAYMENTS);
    }

    public function getCode($method) {
        return $method->getCode() ?? $method->getMethod();
    }

    public function getLabel($code) {
        return @self::LABELS[$code];
    }
}
