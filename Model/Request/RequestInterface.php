<?php
namespace Mondu\Mondu\Model\Request;

interface RequestInterface
{
    public function process($params);
    public function setCommonHeaders($headers);

    function request($params);
}
