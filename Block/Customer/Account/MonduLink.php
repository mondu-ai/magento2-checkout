<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Customer\Account;

use Magento\Framework\View\Element\Html\Link\Current;
use Magento\Framework\App\DefaultPathInterface;
use Magento\Framework\View\Element\Template\Context;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class MonduLink extends Current
{
    /**
     * @param Context $context
     * @param DefaultPathInterface $defaultPath
     * @param ConfigProvider $configProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        DefaultPathInterface $defaultPath,
        private readonly ConfigProvider $configProvider,
        array $data = []
    ) {
        parent::__construct($context, $defaultPath, $data);
    }

    /**
     * @return string
     */
    protected function _toHtml(): string
    {
        if (!$this->configProvider->isBuyerOnboardingEnabled()) {
            return '';
        }

        return parent::_toHtml();
    }
}
