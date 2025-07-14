<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

interface RequestInterface
{
    /**
     * Send request to Mondu Api.
     *
     * @param array|null $params
     * @return mixed
     */
    public function process($params);

    /**
     * Set request headers.
     *
     * @param array $headers
     * @return $this
     */
    public function setCommonHeaders(array $headers): self;

    /**
     * Sets env information.
     *
     * @param array $environment
     * @return $this
     */
    public function setEnvironmentInformation(array $environment): self;

    /**
     * Sets request origin.
     *
     * @param string $origin
     * @return $this
     */
    public function setRequestOrigin(string $origin): self;
}
