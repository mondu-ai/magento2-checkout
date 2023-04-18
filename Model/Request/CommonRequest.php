<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;

abstract class CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;
    protected $envInformation;
    protected $requestParams;
    protected $sendEvents = true;
    protected $requestOrigin;

    /**
     * @var RequestInterface
     */
    protected $errorEventsHandler;

    public function process($params = null)
    {
        $exception = null;
        $data = null;
        try {
            $data = $this->request($params);
        } catch (\Exception $e) {
            $exception = $e;
        }

        if ($this->sendEvents) {
            $this->sendEvents($exception);
        }

        if ($exception) {
            throw $exception;
        }

        return $data;
    }

    public function setCommonHeaders($headers): CommonRequest
    {
        $this->curl->setHeaders($headers);
        return $this;
    }

    public function setEnvironmentInformation($environment): CommonRequest
    {
        if (!isset($this->envInformation)) {
            $this->envInformation = $environment;
        }
        return $this;
    }

    public function setRequestOrigin($origin)
    {
        if (!isset($this->requestOrigin)) {
            $this->requestOrigin = $origin;
        }
        return $this;
    }

    public function sendEvents($exception = null)
    {
        $statusFirstDigit = strval($this->curl->getStatus())[0];
        if ($statusFirstDigit !== '1' && $statusFirstDigit !== '2') {
            $curlData = [
                'response_status' => (string) $this->curl->getStatus(),
                'response_body' => json_decode($this->curl->getBody(), true) ?? [],
                'request_body' => json_decode($this->requestParams ?? '', true) ?? [],
                'origin_event' => $this->requestOrigin
            ];

            $data = array_merge($this->envInformation, $curlData);

            if ($exception) {
                $data = array_merge($data, [
                    'error_trace' => $exception->getTraceAsString(),
                    'error_message' => $exception->getMessage()
                ]);
            } else {
                $data = array_merge($data, [
                    'error_trace' => json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS))
                ]);
            }

            $this->errorEventsHandler->process($data);
        }
    }

    public function sendRequestWithParams($method, $url, $params = null)
    {
        $this->requestParams = $params;

        if ($method === 'post') {
            // Ensure we never send the "Expect: 100-continue" header
            $this->curl->addHeader('Expect', '');
        }

        if ($params) {
            $this->curl->{$method}($url, $params);
        } else {
            $this->curl->{$method}($url);
        }
        return $this->curl->getBody();
    }

    public function setErrorEventsHandler($handler)
    {
        $this->errorEventsHandler = $handler;
        return $this;
    }
}
