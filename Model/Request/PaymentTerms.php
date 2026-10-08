<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class PaymentTerms extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @param Curl $curl
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * Fetches the payment terms (net terms per country) available for the merchant, optionally
     * narrowed to one order source and/or one payment method (see
     * UrlBuilder::getPaymentTermsUrl()).
     *
     * Response shape: {"payment_terms":[{"net_term":30,"country_code":"DE"}, …]}
     *
     * @param array|null $params e.g. ['source' => 'async']
     * @throws LocalizedException
     * @return array|null
     */
    public function request($params = null)
    {
        $resultJson = $this->sendRequestWithParams('get', $this->urlBuilder->getPaymentTermsUrl((array) $params));

        if (!$resultJson) {
            throw new LocalizedException(__('something went wrong'));
        }

        $result = json_decode($resultJson, true);

        return $result['payment_terms'] ?? null;
    }
}
