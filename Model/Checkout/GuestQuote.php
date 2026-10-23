<?php

declare(strict_types=1);

namespace Mondu\Mondu\Model\Checkout;

use Magento\Customer\Model\Group as CustomerGroup;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Turns a quote into a guest quote before it is submitted.
 *
 * Shared by the buyer's return from the hosted checkout and the server side recovery,
 * so both placement paths build the same guest order.
 */
class GuestQuote
{
    /**
     * Prepares the quote for submitting as a guest order.
     *
     * The email the buyer gave on the billing address wins; the quote's own email
     * covers a billing address saved without one.
     *
     * @param CartInterface $quote
     * @return CartInterface
     */
    public function prepare(CartInterface $quote): CartInterface
    {
        $billingAddress = $quote->getBillingAddress();
        $email = ($billingAddress->getOrigData('email') ?? $billingAddress->getEmail())
            ?: $quote->getCustomerEmail();

        $quote->setCustomerId(null)
            ->setCustomerEmail($email)
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(CustomerGroup::NOT_LOGGED_IN_ID);

        return $quote;
    }
}
