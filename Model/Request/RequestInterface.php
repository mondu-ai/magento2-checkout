<?php
namespace Mondu\Mondu\Model\Request;

interface RequestInterface
{
    /**
     * Send request to Mondu Api
     *
     * @param array|null $params
     * @return mixed
     */
    public function process($params);

    /**
     * Set request headers
     *
     * @param array $headers
     * @return $this
     */
    public function setCommonHeaders($headers);

    /**
     * Sets env information
     *
     * @param array $environment
     * @return $this
     */
    public function setEnvironmentInformation($environment);

    /**
     * Sets request origin
     *
     * @param string $origin
     * @return $this
     */
    public function setRequestOrigin($origin);
}
