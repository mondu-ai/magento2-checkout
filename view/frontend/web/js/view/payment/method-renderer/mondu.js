define([
    'jquery',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/redirect-on-success',
    'Magento_Ui/js/model/messages',
    'Magento_Checkout/js/model/payment/additional-validators',
    'Magento_Checkout/js/action/set-payment-information',
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function (
    $,
    quote,
    Component,
    redirectOnSuccessAction,
    Messages,
    additionalValidators,
    SetPaymentInformationAction,
    customerData,
    $t
) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Mondu_Mondu/payment/form',
            monduSdkLoaded: false,
        },

        initObservable: function () {
            var self = this;

            if (!window.monduLoading) {
                window.monduLoading = true;
                var monduSkd = document.createElement("script");
                monduSkd.onload = function () {
                    self.monduSdkLoaded = true;
                };
                monduSkd.src = self.getMonduSdkUrl();
                document.head.appendChild(monduSkd);
            }

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

        getMonduSdkUrl: function () {
            var self = this;
            return window.checkoutConfig.payment[self.getCode()].sdkUrl;
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
                case 'monduinstallmentbyinvoice':
                    payment_method = 'installment_by_invoice';
                    break;
                case 'mondupaynow':
                    payment_method = 'pay_now';
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
                    // On a non-2xx jQuery hands this callback the jqXHR, not the parsed body,
                    // so the reason we put in the response would otherwise never be shown.
                    var body = (res && res.responseJSON) ? res.responseJSON : res;

                    if (body && body.token && !body.error) {
                        self.handlePayment(body.source, body);
                        return;
                    } else {
                        self.isPlaceOrderActionAllowed(true);
                        self.messageContainer.addErrorMessage({
                            message: (body && body.message)
                                ? body.message
                                : $t('Error placing an order. Please try again later.'),
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

        handlePayment: function (source, res) {
            var self = this;
            if (source === 'hosted') {
                // Only the cart section: dropping checkout-data leaves the cart page's shipping
                // estimator with no address, and it then saves the store default country and an
                // empty postcode onto the quote when the buyer comes back from Mondu.
                customerData.invalidate(['cart']);
                $.mage.redirect(res.hosted_checkout_url);
                return;
            }

            if (source === 'widget') {
                self.openWidget(res.token);
            }
        },

        openWidget: function (token) {
            var self = this;
            $(
              '<div id="mondu-checkout-widget" style="position: fixed; top: 0;right: 0;left: 0;bottom: 0; z-index: 99999999;"></div>'
            ).appendTo("body");
            window.monduCheckout.render({
                token,
                onCancel: () => {
                    $("#mondu-checkout-widget").remove();
                    self.isPlaceOrderActionAllowed(true);
                    $("body").trigger("processStop");
                },
                onSuccess: () => {
                    self.getPlaceOrderDeferredObject()
                      .fail(function () {
                          self.isPlaceOrderActionAllowed(true);
                          $("body").trigger("processStop");
                      })
                      .done(function () {
                          self.afterPlaceOrder();
                          if (self.redirectAfterPlaceOrder) {
                              redirectOnSuccessAction.execute();
                          }
                      });
                    $("#mondu-checkout-widget").remove();
                    $("body").trigger("processStop");
                },
                onClose: () => {},
            });
        },
    });
});
