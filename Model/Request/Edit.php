<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class Edit extends CommonRequest
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var string
     */
    protected $uid;

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
        if (!$this->uid) {
            throw new LocalizedException(__('No order uid provided to adjust the order'));
        }

        $url = $this->urlBuilder->getOrderAdjustmentUrl($this->uid);
        $resultJson = $this->sendRequestWithParams('post', $url, json_encode($params));

        if (!$resultJson) {
            throw new LocalizedException(__('Mondu: something went wrong'));
        }

        return json_decode($resultJson, true);
    }

    /**
     * Sets order uid ( used before sending the request ).
     *
     * @param string $uid
     * @return $this
     */
    public function setOrderUid(string $uid)
    {
        $this->uid = $uid;
        return $this;
    }
}
