<?php

namespace Mondu\Mondu\Model\Request;

use Magento\Framework\HTTP\Client\Curl;

abstract class CommonRequest {
    /**
     * @var Curl
     */
    protected $curl;
    protected $envInformation;

    public function process($params) {
        $exception = null;
        $data = null;

        try {
            $data = $this->request($params);
        } catch (\Exception $e) {
            $exception = $e;
        }

        $this->sendEvents($exception);
        return $data;
    }

    public function setCommonHeaders($headers): CommonRequest
    {
        $this->curl->setHeaders($headers);
        return $this;
    }

    public function setEnvironmentInformation($environment): CommonRequest
    {
        if(!isset($this->envInformation)) {
            $this->envInformation = $environment;
        }
        return $this;
    }

    public function sendEvents($exception = null)
    {
        if (strval($this->curl->getStatus())[0] !== '2') {
            $curlData = [
                'status' => $this->curl->getStatus(),
                'message' => $this->curl->getBody()
            ];

            $data = array_merge($this->envInformation, $curlData);

            if ($exception) {
                $data = array_merge($data, [
                    'trace' => $exception->getTraceAsString(),
                    'error_message' => $exception->getMessage()
                ]);
            }

            if ($exception) throw $exception;
        }
    }
}
