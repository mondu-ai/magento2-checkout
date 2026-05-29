<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

class HostedOnboarding extends CommonRequest implements RequestInterface
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
     * @param array|null $params
     * @throws LocalizedException
     * @return array
     */
    public function request($params = null)
    {
        $resultJson = $this->sendRequestWithParams(
            'post',
            $this->urlBuilder->getBuyerHostedOnboardingUrl(),
            json_encode($params)
        );

        if (!$resultJson) {
            throw new LocalizedException(__('Hosted onboarding request failed'));
        }

        $result = json_decode($resultJson, true);

        if (empty($result['hosted_page_url'])) {
            $this->logger?->error('Hosted onboarding: no hosted_page_url in response', [
                'response' => $result,
            ]);
            throw new LocalizedException(__('Hosted onboarding: invalid response'));
        }

        return $result;
    }
}
