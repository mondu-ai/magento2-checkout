<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Framework\App\Request;

use Closure;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\CsrfValidator as MageCsrfValidator;
use Magento\Framework\App\RequestInterface;

class CsrfValidator
{
    /**
     * Skips CSRF validation for Mondu requests (e.g., webhook endpoints).
     *
     * @param MageCsrfValidator $subject
     * @param Closure $proceed
     * @param RequestInterface $request
     * @param ActionInterface $action
     * @return void
     */
    public function aroundValidate(
        MageCsrfValidator $subject,
        Closure $proceed,
        RequestInterface $request,
        ActionInterface $action
    ): void {
        // Magento 2.1.x, 2.2.x
        if ($request->getModuleName() === 'mondu') {
            return;
        }

        // Magento 2.3.x
        if (str_contains($request->getOriginalPathInfo(), '/mondu/webhooks/index')) {
            return;
        }

        $proceed($request, $action);
    }
}
