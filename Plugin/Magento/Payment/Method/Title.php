<?php

declare(strict_types=1);

namespace Mondu\Mondu\Plugin\Magento\Payment\Method;

use Magento\Payment\Model\MethodInterface;

/**
 * Prefixes the Mondu payment titles with the brand in the admin.
 *
 * The admin renders the raw configured title (Magento never translates it there),
 * so an admin creating an order sees "Invoice" or "SEPA direct debit" with nothing
 * pointing at Mondu. The storefront is left alone: there the title runs through
 * the translation dictionary and the Mondu logo is shown next to it already, so
 * prefixing would both duplicate the branding and break the translations, which
 * are keyed on the exact configured string.
 *
 * Registered for the adminhtml area only, see etc/adminhtml/di.xml.
 */
class Title
{
    private const BRAND = 'Mondu';

    /**
     * Prepends the brand to the configured payment title.
     *
     * @param MethodInterface $subject
     * @param string|null $result
     * @return string|null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetTitle(MethodInterface $subject, $result)
    {
        $title = (string) $result;

        if ($title === '' || stripos($title, self::BRAND) !== false) {
            // Already branded, either by us or by a merchant who named the
            // method themselves. Prefixing again would read "Mondu Mondu ...".
            return $result;
        }

        return self::BRAND . ' ' . $title;
    }
}
