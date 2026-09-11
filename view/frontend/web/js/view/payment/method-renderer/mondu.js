define([
    'jquery',
    'knockout',
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
    ko,
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

            // Terms the merchant enabled, narrowed to this payment method and to the
            // country the buyer is ordering for, the two dimensions order creation
            // validates. Recomputed rather than read once, because the buyer can
            // change the address without reloading the checkout.
            self.availableNetTerms = ko.computed(function () {
                return self.getNetTermsForCountry(self.getNetTermConfig());
            });

            self.selectedNetTerm = ko.observable(null);

            // Keep the selection answerable to the list: an address change can drop
            // the term the buyer picked, and sending it anyway earns a 422.
            self.availableNetTerms.subscribe(function (netTerms) {
                if (netTerms.indexOf(self.selectedNetTerm()) === -1) {
                    self.selectedNetTerm(self.getPreferredNetTerm(netTerms));
                }
            });
            self.selectedNetTerm(self.getPreferredNetTerm(self.availableNetTerms()));

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
            var data = {
                method: this.item.method,
            };

            // Same field the admin order-create form uses, so the term reaches
            // payment.additional_information through the one observer and is on
            // the order for the invoice PDF.
            if (this.selectedNetTerm && this.selectedNetTerm()) {
                data.additional_data = {
                    mondu_net_term: this.selectedNetTerm(),
                };
            }

            return data;
        },

        /**
         * Terms per country for this payment method, already narrowed to what the
         * merchant enabled for it. Absent for a method the merchant left empty and
         * for instalments, which cannot carry a term at all.
         */
        getNetTermConfig: function () {
            var config = window.checkoutConfig.monduNetTerms || {};

            return (config.byMethod || {})[this.getCode()] || {};
        },

        /**
         * Terms allowed for the country the buyer is ordering for.
         *
         * The wildcard key holds terms the API returned without a country, which
         * count everywhere. An unknown country yields nothing, which hides the
         * field and sends no term at all.
         */
        getNetTermsForCountry: function (byCountry) {
            var address = quote.billingAddress() || quote.shippingAddress();
            var countryId = (address && address.countryId) ? address.countryId : null;

            if (countryId && byCountry[countryId]) {
                return byCountry[countryId];
            }

            return byCountry['*'] || [];
        },

        /**
         * 30 days when the merchant offers it, otherwise the closest term to it,
         * preferring the shorter one on a tie. Mirrors the admin form.
         */
        getPreferredNetTerm: function (netTerms) {
            if (!netTerms.length) {
                return null;
            }
            if (netTerms.indexOf(30) !== -1) {
                return 30;
            }

            return netTerms.reduce(function (closest, netTerm) {
                return Math.abs(netTerm - 30) < Math.abs(closest - 30) ? netTerm : closest;
            });
        },

        /**
         * The buyer only gets a choice when there is one to make.
         */
        isNetTermSelectorVisible: function () {
            return this.availableNetTerms().length > 1;
        },

        /**
         * A single term is still shown, just not as a question: the buyer is told
         * when the invoice falls due either way.
         */
        isNetTermTextVisible: function () {
            return this.availableNetTerms().length === 1;
        },

        isNetTermVisible: function () {
            return this.availableNetTerms().length > 0;
        },

        getNetTermLabel: function (netTerm) {
            return $t('%1 days').replace('%1', netTerm);
        },

        getSingleNetTermLabel: function () {
            var netTerms = this.availableNetTerms();

            return netTerms.length ? this.getNetTermLabel(netTerms[0]) : '';
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
