<?php

declare(strict_types=1);

namespace Mondu\Mondu\Block\Adminhtml\System\Config\Fieldset;

use Magento\Backend\Block\Context;
use Magento\Backend\Model\Auth\Session;
use Magento\Config\Block\System\Config\Form\Fieldset;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\View\Helper\Js;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

class Payment extends Fieldset
{
    /**
     * @param Context $context
     * @param Session $authSession
     * @param Js $jsHelper
     * @param SecureHtmlRenderer $secureRenderer
     * @param array $data
     */
    public function __construct(
        Context $context,
        Session $authSession,
        Js $jsHelper,
        protected SecureHtmlRenderer $secureRenderer,
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
        $html = '<div class="config-heading">';

        $htmlId = $element->getHtmlId();
        $html .= '<div class="button-container"><button type="button"'
            . ($this->_isCollapseState($element) ? '' : ' disabled="disabled"')
            . ' class="button action-configure'
            . ($this->_isCollapseState($element) ? ' open' : '')
            . '" id="'
            . $htmlId
            . '-head" >'
            . '<span class="state-closed">'
            . __('Configure')
            . '</span><span class="state-opened">'
            . __('Close')
            . '</span></button>';

        $html .= $this->secureRenderer->renderEventListenerAsTag(
            'onclick',
            "monduToggleSolution.call(this, '"
            . $htmlId
            . "', '"
            . $this->getUrl('adminhtml/*/state')
            . "'); event.preventDefault();",
            'button#' . $htmlId . '-head'
        );

        $html .= '</div><div class="heading"><strong>'
            . $element->getLegend()
            . '</strong>';

        if ($element->getComment()) {
            $html .= '<div class="heading-intro">'
                . $element->getComment()
                . '</div>';
        }
        $html .= '<div class="config-alt"></div></div></div>';

        return $html;
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
