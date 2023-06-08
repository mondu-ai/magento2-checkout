<?php
namespace Mondu\Mondu\Block\Log\Edit;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\Element\UiComponent\Context;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

class GenericButton implements ButtonProviderInterface
{
    /**
     * @var Context
     */
    protected $context;

    /**
     * @var AuthorizationInterface
     */
    protected $_authorization;

    /**
     * @param Context $context
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        Context $context,
        AuthorizationInterface $authorization
    ) {
        $this->context = $context;
        $this->_authorization = $authorization;
    }

    /**
     * GetId
     *
     * @return int
     */
    public function getId(): int
    {
        return (int)$this->context->getRequestParams('entity_id');
    }

    /**
     * Generate url by route and parameters
     *
     * @param   string $route
     * @param   array $params
     * @return  string
     */
    public function getUrl($route = '', $params = [])
    {
        return $this->context->getUrl($route, $params);
    }

    /**
     * @inheritdoc
     */
    public function getButtonData()
    {
        return [];
    }

    /**
     * Check permission for passed action
     *
     * @param string $resourceId
     * @return bool
     */
    protected function _isAllowedAction($resourceId)
    {
        return $this->_authorization->isAllowed($resourceId);
    }
}
