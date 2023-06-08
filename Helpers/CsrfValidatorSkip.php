<?php
namespace Mondu\Mondu\Helpers;

use Closure;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\CsrfValidator;
use Magento\Framework\App\RequestInterface;

class CsrfValidatorSkip
{
    /**
     * AroundValidate
     *
     * @param CsrfValidator $subject
     * @param Closure $proceed
     * @param RequestInterface $request
     * @param ActionInterface $action
     */
    public function aroundValidate(
        $subject,
        Closure $proceed,
        $request,
        $action
    ) {
        /* Magento 2.1.x, 2.2.x */
        if ($request->getModuleName() == 'mondu') {
            return; // Skip CSRF check
        }
        /* Magento 2.3.x */
        if (strpos($request->getOriginalPathInfo(), '/mondu/webhooks/index') !== false) {
            return; // Skip CSRF check
        }
        $proceed($request, $action); // Proceed Magento 2 core functionalities
    }
}
