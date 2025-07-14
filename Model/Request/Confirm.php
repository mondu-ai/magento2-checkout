<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class Confirm extends CommonRequest
{
    public const ORDER_STATE = ['pending', 'confirmed', 'authorized'];

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var bool
     */
    private $validate = true;

    /**
     * @param Curl $curl
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * Request.
     *
     * @param array $params
     * @throws LocalizedException
     * @return mixed
     */
    protected function request($params)
    {
        if (!$params['orderUid']) {
            throw new LocalizedException(__('Error placing an order'));
        }

        $url = $this->urlBuilder->getOrderUrl($params['orderUid']);
        $resultJson = $this->sendRequestWithParams('get', $url);
        $result = json_decode($resultJson, true);

        if ($this->validate && !in_array($result['order']['state'] ?? null, self::ORDER_STATE, true)) {
            throw new LocalizedException(__('Error placing an order'));
        }

        return $result;
    }

    /**
     * SetValidate ( will check if order state is in self::ORDER_STATE ).
     *
     * @param bool $validate
     * @return $this
     */
    public function setValidate(bool $validate): Confirm
    {
        $this->validate = $validate;
        return $this;
    }
}
