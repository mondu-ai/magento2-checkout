<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;
use Mondu\Mondu\Helpers\Request\UrlBuilder;

/**
 * Probes whether POST /orders/create_async is enabled for the merchant account.
 *
 * Mondu exposes no capability endpoint, so the endpoint itself is the source of
 * truth: an empty payload comes back as 422 (validation reached, so the source
 * is switched on) when the account has it, and as 401/403/404 when it does not.
 * The empty payload creates nothing.
 */
class AsyncOrderSupport extends CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * A 422 is the expected answer here, so this must not report a plugin error event.
     *
     * @var bool
     */
    protected bool $sendEvents = false;

    /**
     * @param Curl $curl
     * @param UrlBuilder $urlBuilder
     */
    public function __construct(Curl $curl, private readonly UrlBuilder $urlBuilder)
    {
        $this->curl = $curl;
    }

    /**
     * Returns true when the account may create async (backend) orders.
     *
     * @param array|null $params
     * @return bool
     */
    public function request($params = null)
    {
        $this->sendRequestWithParams('post', $this->urlBuilder->getCreateAsyncOrderUrl(), '{}');

        $status = (int) $this->curl->getStatus();

        // 422 = the payload was rejected, i.e. the endpoint is live for this account.
        // 401/403/404 = the source is not enabled. Anything else (5xx, proxy noise,
        // no answer at all) is not evidence of absence, so we keep the feature on.
        return !in_array($status, [401, 403, 404], true);
    }
}
