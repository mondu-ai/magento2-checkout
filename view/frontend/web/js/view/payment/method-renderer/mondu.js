define([
    "jquery",
    "Magento_Checkout/js/model/quote",
    "Magento_Checkout/js/view/payment/default",
    "Magento_Checkout/js/action/redirect-on-success",
    "Magento_Ui/js/model/messages",
    "Magento_Checkout/js/model/payment/additional-validators",
    "Magento_Checkout/js/view/billing-address",
    "Magento_Checkout/js/action/set-payment-information",
], function (
  $,
  quote,
  Component,
  redirectOnSuccessAction,
  Messages,
  additionalValidators,
  billingAddress,
  SetPaymentInformationAction
) {
    "use strict";
    return Component.extend({
        defaults: {
            template: "Mondu_Mondu/payment/form",
            transactionResult: "",
            monduSkdLoaded: false,
        },
        isBillingSameAsShippng: true,

        initObservable: function () {
            self = this;
            self._super().observe(["transactionResult"]);
            billingAddress().isAddressSameAsShipping.subscribe(function (
              isSame
            ) {
                self.isBillingSameAsShipping = isSame;
            });

            if(!window.monduLoading) {
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
                additional_data: {
                    transaction_result: this.transactionResult(),
                },
            };
        },

        getTransactionResults: function () {
            var self = this;
            return _.map(
              window.checkoutConfig.payment[self.getCode()].transactionResults,
              function (value, key) {
                  return {
                      value: key,
                      transaction_result: value,
                  };
              }
            );
        },

        getMonduCheckoutTokenUrl: function () {
            return window.checkoutConfig.payment[self.getCode()]
              .monduCheckoutTokenUrl;
        },

        getMonduSdkUrl: function () {
            return window.checkoutConfig.payment[self.getCode()].sdkUrl;
        },

        getCustomerEmail: function () {
            if (quote.guestEmail) {
                return quote.guestEmail;
            } else {
                return customerData.email;
            }
        },

        openCheckout: function (token) {
            $(
              '<div id="mondu-checkout-widget" style="position: fixed; top: 0;right: 0;left: 0;bottom: 0; z-index: 99999999;"/>'
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

        placeOrder: function (data, event) {
            var self = this;
            if (!additionalValidators.validate()) return;
            if (event) {
                event.preventDefault();
            }
            if (self.isBillingSameAsShippng) {
                quote.billingAddress(quote.shippingAddress());
            }
            $("body").trigger("processStart");

            var initCheckout = function () {
                $.ajax({
                    url: self.getMonduCheckoutTokenUrl(),
                    method: "get",
                    data: {
                        email: self.getCustomerEmail(),
                        payment_method: self.getCode() === 'mondusepa' ? 'direct_debit' : 'bank_transfer'
                    },
                }).always(function (res) {
                    if (res && res.token && !res.error) {
                        self.openCheckout(res.token);
                        return;
                    } else {
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
