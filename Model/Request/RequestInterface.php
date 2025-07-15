<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Request;

interface RequestInterface
{
    /**
     * Processes the request and optionally dispatches error events.
     *
     * @param array|null $params
     * @return mixed
     */
    public function process($params);

    /**
     * Sets common HTTP headers for the request.
     *
     * @param array $headers
     * @return $this
     */
    public function setCommonHeaders(array $headers): self;

    /**
     * Sets the environment data for inclusion in event payloads.
     *
     * @param array $environment
     * @return $this
     */
    public function setEnvironmentInformation(array $environment): self;

    /**
     * Sets the origin identifier for the current request.
     *
     * @param string $origin
     * @return $this
     */
    public function setRequestOrigin(string $origin): self;
}
