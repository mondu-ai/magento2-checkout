define([
    "jquery",
    "Magento_Checkout/js/model/quote",
    "Magento_Checkout/js/view/payment/default",
    "Magento_Checkout/js/action/redirect-on-success",
    "Magento_Ui/js/model/messages",
    "Magento_Checkout/js/model/payment/additional-validators",
    "Magento_Checkout/js/view/billing-address",
    "Magento_Checkout/js/action/set-payment-information",
    'Magento_Customer/js/customer-data'
], function (
    $,
    quote,
    Component,
    redirectOnSuccessAction,
    Messages,
    additionalValidators,
    billingAddress,
    SetPaymentInformationAction,
    customerData
) {
    "use strict";
    return Component.extend({
        defaults: {
            template: "Mondu_Mondu/payment/form",
            monduSkdLoaded: false,
        },
        isBillingSameAsShipping: true,

        initObservable: function () {
            var self = this;
            billingAddress().isAddressSameAsShipping.subscribe(function (
                isSame
            ) {
                self.isBillingSameAsShipping = isSame;
            });

            this.messageContainer = new Messages();

            return self;
        },

        getData: function () {
            return {
                method: this.item.method,
            };
        },

        getMonduCheckoutTokenUrl: function () {
            var self = this;
            return window.checkoutConfig.payment[self.getCode()]
              .monduCheckoutTokenUrl;
        },

        getCustomerEmail: function () {
            if (quote.guestEmail) {
                return quote.guestEmail;
            } else {
                return customerData.email;
            }
        },

        placeOrder: function (data, event) {
            var self = this;
            if (!additionalValidators.validate()) {
                return;
            }
            if (!self.isPlaceOrderActionAllowed() === true) {
                return;
            }
            if (event) {
                event.preventDefault();
            }
            if (self.isBillingSameAsShipping) {
                quote.billingAddress(quote.shippingAddress());
            }
            $("body").trigger("processStart");
            self.isPlaceOrderActionAllowed(false);
            let payment_method;

            switch (self.getCode()) {
                case 'mondusepa':
                    payment_method = 'direct_debit';
                    break;
                case 'monduinstallment':
                    payment_method = 'installment';
                    break;
                default:
                    payment_method = 'invoice';
                    break;
            }

            var initCheckout = function () {
                $.ajax({
                    url: self.getMonduCheckoutTokenUrl(),
                    method: "get",
                    data: {
                        email: self.getCustomerEmail(),
                        payment_method: payment_method
                    },
                }).always(function (res) {
                    if (res && res.token && !res.error) {
                        customerData.invalidate(['cart']);

                        $.mage.redirect(res.hosted_checkout_url);
                        return;
                    } else {
                        self.isPlaceOrderActionAllowed(true);
                        self.messageContainer.addErrorMessage({
                            message: res.message,
                        });
                    }

                    $("body").trigger("processStop");
                });
            };
            SetPaymentInformationAction(
              this.messageContainer,
              self.getData()
            ).then(() => {
                initCheckout();
            }).fail(() => {
                self.isPlaceOrderActionAllowed(true);
                $("body").trigger("processStop");
            })
        },
    });
});
