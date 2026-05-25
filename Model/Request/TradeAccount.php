<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class TradeAccount extends CommonRequest implements RequestInterface
{
    protected $curl;

    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * Creates a Digital Trade Account onboarding session.
     *
     * @param array|null $params [external_reference_id, redirect_urls, applicant?, company_details?, language?]
     * @throws LocalizedException
     * @return mixed
     */
    public function request($params = null)
    {
        $resultJson = $this->sendRequestWithParams(
            'post',
            $this->urlBuilder->getTradeAccountUrl(),
            json_encode($params)
        );

        if (!$resultJson) {
            throw new LocalizedException(__('Failed to create trade account onboarding session'));
        }

        return json_decode($resultJson, true);
    }
}
