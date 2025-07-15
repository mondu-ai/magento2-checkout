<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

use Exception;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;

abstract class CommonRequest implements RequestInterface
{
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var array
     */
    protected array $envInformation;

    /**
     * @var string|null
     */
    protected ?string $requestParams = null;

    /**
     * @var bool
     */
    protected bool $sendEvents = true;

    /**
     * @var string
     */
    protected string $requestOrigin;

    /**
     * @var RequestInterface|null
     */
    protected ?RequestInterface $errorEventsHandler = null;

    /**
     * Sends a request to the Mondu API
     *
     * @param array|null $params
     * @return mixed
     * @throws LocalizedException
     */
    abstract protected function request($params);

    /**
     * Processes the request and optionally dispatches error events.
     *
     * @param mixed $params
     * @return mixed
     * @throws Exception
     */
    public function process($params = null)
    {
        $exception = null;
        $data = null;
        try {
            $data = $this->request($params);
        } catch (Exception $e) {
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

    /**
     * Sets common HTTP headers for the request.
     *
     * @param array $headers
     * @return $this
     */
    public function setCommonHeaders(array $headers): self
    {
        $this->curl->setHeaders($headers);
        return $this;
    }

    /**
     * Sets the environment data for inclusion in event payloads.
     *
     * @param array $environment
     * @return $this
     */
    public function setEnvironmentInformation(array $environment): self
    {
        if (!isset($this->envInformation)) {
            $this->envInformation = $environment;
        }

        return $this;
    }

    /**
     * Sets the origin identifier for the current request.
     *
     * @param string $origin
     * @return $this
     */
    public function setRequestOrigin(string $origin): self
    {
        if (!isset($this->requestOrigin)) {
            $this->requestOrigin = $origin;
        }

        return $this;
    }

    /**
     * Sends error event payload if the response indicates failure.
     *
     * @param Exception|null $exception
     * @return void
     */
    public function sendEvents(?Exception $exception = null): void
    {
        $statusFirstDigit = ((string) $this->curl->getStatus())[0];
        if ($statusFirstDigit !== '1' && $statusFirstDigit !== '2') {
            $curlData = [
                'response_status' => (string) $this->curl->getStatus(),
                'response_body' => json_decode($this->curl->getBody(), true) ?? [],
                'request_body' => json_decode($this->requestParams ?? '', true) ?? [],
                'origin_event' => $this->requestOrigin,
            ];

            $data = array_merge($this->envInformation, $curlData);

            if ($exception) {
                $data = array_merge($data, [
                    'error_trace' => $exception->getTraceAsString(),
                    'error_message' => $exception->getMessage(),
                ]);
            } else {
                $data = array_merge($data, [
                    'error_trace' => json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)),
                ]);
            }

            $this->errorEventsHandler->process($data);
        }
    }

    /**
     * Sends HTTP request to Mondu API with parameters.
     *
     * @param string $method
     * @param string $url
     * @param string|null $params
     * @return string
     */
    public function sendRequestWithParams(string $method, string $url, ?string $params = null): string
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

    /**
     * Sets a custom handler for dispatching Mondu API error events.
     *
     * @param RequestInterface $handler
     * @return $this
     */
    public function setErrorEventsHandler(RequestInterface $handler): self
    {
        $this->errorEventsHandler = $handler;
        return $this;
    }
}
