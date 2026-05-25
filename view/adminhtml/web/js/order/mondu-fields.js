/**
 * Admin order create — Mondu payment method extra fields toggler.
 *
 * Registered by mondu_fields.phtml via `require(['Mondu_Mondu/js/order/mondu-fields'], …)`.
 * `config.requiredByMethod` comes from PHP (\Mondu\Mondu\Model\Payment\AsyncOrderFields)
 * so the required-fields mapping has a single source of truth.
 *
 * @param {{requiredByMethod: Object.<string,string[]>}} config
 * @param {HTMLElement} element  fieldset root
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var FIELDSET_SELECTOR = '#mondu-order-create-fields';

    // Shared cache so values survive AJAX reloads of the billing_method area.
    // Key = input/select id (matches AsyncOrderFields::FIELD_*). Module-level, intentional.
    if (typeof window.MonduAdminFieldCache === 'undefined') {
        window.MonduAdminFieldCache = {};
    }
    var cache = window.MonduAdminFieldCache;

    /**
     * Live snapshot of all values into the cache. Queries the DOM by selector
     * each time so it works even after Prototype .update() replaces the node.
     */
    function snapshot() {
        $(FIELDSET_SELECTOR).find('input, select').each(function () {
            if (this.id) {
                cache[this.id] = $(this).val();
            }
        });
    }

    // Wrap window.order.loadArea once: before any AJAX reload of billing_method,
    // snapshot values so the next component instance can restore them.
    function wrapLoadAreaOnce() {
        if (!window.order || typeof window.order.loadArea !== 'function') { return; }
        if (window.order._monduLoadAreaWrapped) { return; }
        var origLoadArea = window.order.loadArea.bind(window.order);
        window.order.loadArea = function (area, indicator, params, callback) {
            snapshot();
            return origLoadArea(area, indicator, params, callback);
        };
        window.order._monduLoadAreaWrapped = true;
    }

    return function (config, element) {
        var $fs = $(element);
        var requiredByMethod = config.requiredByMethod || {};

        function isMonduMethod(code) {
            return Object.prototype.hasOwnProperty.call(requiredByMethod, code);
        }

        function applyMethod(code) {
            if (!isMonduMethod(code)) {
                $fs.hide();
                $fs.find('input, select').prop('required', false);
                $fs.find('[data-mondu-field]').removeClass('_required');
                return;
            }

            $fs.show();
            var required = requiredByMethod[code] || [];
            $fs.find('[data-mondu-field]').each(function () {
                var $wrap = $(this);
                var fieldName = $wrap.data('mondu-field');
                var isRequired = required.indexOf(fieldName) !== -1;

                $wrap.toggle(isRequired);
                $wrap.toggleClass('_required', isRequired);
                $wrap.find('input, select').prop('required', isRequired);
            });
        }

        function restoreFromCache() {
            $fs.find('input, select').each(function () {
                var id = this.id;
                if (id && Object.prototype.hasOwnProperty.call(cache, id)) {
                    $(this).val(cache[id]);
                }
            });
        }

        function currentMethodCode() {
            var $checked = $('input[name="payment[method]"]:checked');
            return $checked.length ? String($checked.val()) : '';
        }

        // Per-instance: keep cache hot on every edit.
        $fs.on('change input', 'input, select', function () {
            if (this.id) {
                if (this.id === 'mondu_iban') {
                    this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                }
                cache[this.id] = $(this).val();
            }
        });

        restoreFromCache();

        // Store config globally so the persistent observer can re-apply after AJAX reloads
        window.MonduFieldsConfig = requiredByMethod;

        applyMethod(currentMethodCode());

        $(document).off('change.monduFields', 'input[name="payment[method]"]')
            .on('change.monduFields', 'input[name="payment[method]"]', function () {
                applyMethod(String($(this).val()));
            });

        wrapLoadAreaOnce();

        // Persistent global observer: survives billing_method AJAX block replacement.
        // Magento admin uses Prototype's Ajax.Request, not jQuery.ajax, so we
        // register a Prototype Ajax.Responder to catch AJAX completions.
        if (!window._monduAjaxObserverBound && typeof Ajax !== 'undefined' && Ajax.Responders) {
            window._monduAjaxObserverBound = true;
            Ajax.Responders.register({
                onComplete: function () {
                    setTimeout(function () {
                        var cfg = window.MonduFieldsConfig;
                        if (!cfg) { return; }
                        var $el = $(FIELDSET_SELECTOR);
                        if (!$el.length) { return; }
                        var $checked = $('input[name="payment[method]"]:checked');
                        var code = $checked.length ? String($checked.val()) : '';
                        if (!code || !Object.prototype.hasOwnProperty.call(cfg, code)) {
                            $el.hide();
                            return;
                        }
                        $el.show();
                        var required = cfg[code] || [];
                        $el.find('[data-mondu-field]').each(function () {
                            var fieldName = $(this).data('mondu-field');
                            var show = required.indexOf(fieldName) !== -1;
                            $(this).toggle(show).toggleClass('_required', show);
                            $(this).find('input, select').prop('required', show);
                        });
                        var c = window.MonduAdminFieldCache || {};
                        $el.find('input, select').each(function () {
                            if (this.id && Object.prototype.hasOwnProperty.call(c, this.id)) {
                                $(this).val(c[this.id]);
                            }
                        });
                        // Re-bind IBAN sanitizer after AJAX reload
                        $el.off('input.monduIban', '#mondu_iban')
                            .on('input.monduIban', '#mondu_iban', function () {
                                this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                            });
                    }, 500);
                }
            });
        }
    };
});
