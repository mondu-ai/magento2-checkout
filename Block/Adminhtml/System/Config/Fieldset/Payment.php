<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Adminhtml\System\Config\Fieldset;

use Magento\Backend\Block\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\Js;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Mondu\Mondu\Helpers\ModuleHelper;
use Composer\InstalledVersions;

class Payment extends Fieldset
{
    /**
     * @param Context $context
     * @param Session $authSession
     * @param Js $jsHelper
     * @param SecureHtmlRenderer $secureRenderer
     * @param ModuleHelper $moduleHelper
     * @param array $data
     */
    public function __construct(
        Context $context,
        Session $authSession,
        Js $jsHelper,
        protected SecureHtmlRenderer $secureRenderer,
        private readonly ModuleHelper $moduleHelper,
        array $data = [],
    ) {
        parent::__construct($context, $authSession, $jsHelper, $data, $secureRenderer);
    }

    /**
     * Adds CSS classes to indicate collapsible button state.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getFrontendClass($element)
    {
        return parent::_getFrontendClass($element)
            . ' with-button'
            . ($this->_isCollapseState($element) ? ' open active' : '');
    }

    /**
     * Returns HTML for the configuration fieldset header and toggle button.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getHeaderTitleHtml($element)
    {
        $version = $this->getModuleVersion();

        $html = '<div class="config-heading">';

        $html .= '<div class="mondu-payment-logo"></div>';
        $html .= '<div class="mondu-payment-text">';
        $html .= 'Buy Now, Pay Later for Online B2B Checkout<br/>';
        $html .= 'Increase your revenue with Mondu’s solution, without the operational burden.<br/>';
        $html .= 'v' . $version;
        $html .= '</div>';

        $htmlId = $element->getHtmlId();
        $html .= '<div class="button-container"><button type="button"'
            . ($this->_isCollapseState($element) ? '' : ' disabled="disabled"')
            . ' class="button action-configure'
            . ($this->_isCollapseState($element) ? ' open' : '')
            . '" id="' . $htmlId . '-head" >'
            . '<span class="state-closed">' . __('Configure') . '</span>'
            . '<span class="state-opened">' . __('Close') . '</span></button>';

        $html .= $this->secureRenderer->renderEventListenerAsTag(
            'onclick',
            "monduToggleSolution.call(this, '"
            . $htmlId
            . "', '"
            . $this->getUrl('adminhtml/*/state')
            . "'); event.preventDefault();",
            'button#' . $htmlId . '-head'
        );

        $html .= '</div>';
        $html .= '<div class="heading"><strong>' . $element->getLegend() . '</strong></div>';
        $html .= '<div class="config-alt"></div></div>';

        return $html;
    }

    /**
     * Returns the current module version string, falling back to ModuleHelper if Composer data is unavailable.
     *
     * @return string
     */
    private function getModuleVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class)) {
                $version = InstalledVersions::getPrettyVersion('mondu_gmbh/magento2-payment');
                if ($version && $version !== 'dev-master' && $version !== 'dev-main') {
                    return $version;
                }
            }
        } catch (\Throwable $e) {
            $this->_logger->warning(
                'Mondu: Could not get version from InstalledVersions',
                ['error' => $e->getMessage()]
            );
        }

        try {
            return $this->moduleHelper->getModuleVersion();
        } catch (\Throwable $e) {
            $this->_logger->warning('Mondu: Could not get module version', ['error' => $e->getMessage()]);
            return 'unknown';
        }
    }

    /**
     * Suppresses the default comment block output.
     *
     * @param AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getHeaderCommentHtml($element)
    {
        return '';
    }

    /**
     * Always renders the fieldset in collapsed (opened) state.
     *
     * @param AbstractElement $element
     * @return bool
     */
    protected function _isCollapseState($element)
    {
        return true;
    }

    /**
     * Returns JS required for fieldset toggle behavior.
     *
     * @param AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getExtraJs($element)
    {
        $script = <<<'JS'
                require(['prototype'], function () {
                    window.monduToggleSolution = function (id, url) {
                        let doScroll = false;
                        Fieldset.toggleCollapse(id, url);
                        if ($(this).hasClassName("open")) {
                            $$(".with-button button.button").forEach(function (anotherButton) {
                                if (anotherButton !== this && $(anotherButton).hasClassName("open")) {
                                    $(anotherButton).click();
                                    doScroll = true;
                                }
                            }.bind(this));
                        }
                        if (doScroll) {
                            const pos = Element.cumulativeOffset($(this));
                            window.scrollTo(pos[0], pos[1] - 45);
                        }
                    }
                });
        JS;

        return $this->_jsHelper->getScript($script);
    }
}
