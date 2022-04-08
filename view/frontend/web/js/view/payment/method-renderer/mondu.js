
define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/redirect-on-success',
        'Magento_Ui/js/model/messages',
    ],
    function ($, quote, Component, redirectOnSuccessAction, Messages) {
        'use strict';
        return Component.extend({
            defaults: {
                template: 'Mondu_Mondu/payment/form',
                transactionResult: '',
                monduSkdLoaded: false
            },

            initObservable: function () {
                self = this;
                self._super()
                    .observe([
                        'transactionResult'
                    ]);

                var monduSkd = document.createElement('script');
                monduSkd.onload = function() {
                    self.monduSdkLoaded = true;
                }
                monduSkd.src= self.getMonduSdkUrl();
                document.head.appendChild(monduSkd);
                this.messageContainer = new Messages();

                return self;
            },

            getCode: function () {
                return 'mondu';
            },

            getData: function () {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        'transaction_result': this.transactionResult()
                    }
                };
            },

            getTransactionResults: function () {
                return _.map(window.checkoutConfig.payment.mondu.transactionResults, function (value, key) {
                    return {
                        'value': key,
                        'transaction_result': value
                    }
                });
            },

            getMonduCheckoutTokenUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].monduCheckoutTokenUrl;
            },

            getMonduSdkUrl: function () {
                return window.checkoutConfig.payment[self.getCode()].sdkUrl;
            },

            getCustomerEmail: function() {
                if (quote.guestEmail) {
                    return quote.guestEmail;
                } else {
                    return customerData.email;
                }
            },

            openCheckout: function(token) {
                $('<div id="mondu-checkout-widget" style="position: fixed; top: 0;right: 0;left: 0;bottom: 0; z-index: 99999999;"/>').appendTo('body');
                window.monduCheckout.render({
                    token,
                    onCancel: () => {
                        $('#mondu-checkout-widget').remove();
                        self.isPlaceOrderActionAllowed(true);
                        $('body').trigger('processStop');
                    },
                    onSuccess: () => {
                        self.getPlaceOrderDeferredObject().fail(
                            function() {
                                self.isPlaceOrderActionAllowed(true);
                                $('body').trigger('processStop');
                            }
                          ).done(
                            function() {
                                self.afterPlaceOrder();
                                if (self.redirectAfterPlaceOrder) {
                                    redirectOnSuccessAction.execute();
                                }
                            }
                          );
                          $('#mondu-checkout-widget').remove();
                          $('body').trigger('processStop');
                    },
                    onClose: () => {
                    }
                });
            },

            placeOrder: function(data, event) {
                if (event) {
                    event.preventDefault();
                }

                $('body').trigger('processStart');

                $.ajax({
                    url: self.getMonduCheckoutTokenUrl(),
                    method: 'get',
                    data: {
                        email: self.getCustomerEmail()
                    }
                }).always(function(res) {
                    if(res && res.token && !res.error) {
                        self.openCheckout(res.token);
                        return;
                    } else {
                        self.messageContainer.addErrorMessage({
                            message: res.message
                        });
                    }

                    $('body').trigger('processStop');
                });
            }
        });
    }
);
