define([
    'jquery'
], function ($) {
    'use strict';

    var FIELDSET_SELECTOR = '#mondu-order-create-fields';

    return function (config) {
        var requiredByMethod = config.requiredByMethod || {};

        window.MonduFieldsConfig = requiredByMethod;

        if (typeof window.MonduAdminFieldCache === 'undefined') {
            window.MonduAdminFieldCache = {};
        }
        var cache = window.MonduAdminFieldCache;

        function currentMethodCode() {
            var $checked = $('input[name="payment[method]"]:checked');
            return $checked.length ? String($checked.val()) : '';
        }

        function applyMethod(code) {
            var $fs = $(FIELDSET_SELECTOR);
            if (!$fs.length) return;

            var isMondu = Object.prototype.hasOwnProperty.call(requiredByMethod, code);
            if (!isMondu) {
                $fs.hide();
                $fs.find('input, select').prop('required', false);
                $fs.find('[data-mondu-field]').removeClass('_required');
                return;
            }

            var required = requiredByMethod[code] || [];
            if (!required.length) {
                $fs.hide();
                $fs.find('input, select').prop('required', false);
                $fs.find('[data-mondu-field]').removeClass('_required');
                return;
            }
            $fs.show();
            $fs.find('[data-mondu-field]').each(function () {
                var $wrap = $(this);
                var fieldName = $wrap.data('mondu-field');
                var isRequired = required.indexOf(fieldName) !== -1;

                $wrap.toggle(isRequired);
                $wrap.toggleClass('_required', isRequired);
                $wrap.find('input, select').prop('required', isRequired);
            });

            $fs.find('input, select').each(function () {
                if (this.id && Object.prototype.hasOwnProperty.call(cache, this.id)) {
                    $(this).val(cache[this.id]);
                }
            });
        }

        function bindFieldsetEvents() {
            var $fs = $(FIELDSET_SELECTOR);
            if (!$fs.length || $fs.data('monduBound')) return;
            $fs.data('monduBound', true);
            $fs.on('change input', 'input, select', function () {
                if (this.id === 'mondu_iban') {
                    this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                }
                if (this.id) {
                    cache[this.id] = $(this).val();
                }
            });
        }

        function snapshot() {
            $(FIELDSET_SELECTOR).find('input, select').each(function () {
                if (this.id) {
                    cache[this.id] = $(this).val();
                }
            });
        }

        function wrapLoadAreaOnce() {
            if (!window.order || typeof window.order.loadArea !== 'function') return;
            if (window.order._monduLoadAreaWrapped) return;
            var origLoadArea = window.order.loadArea.bind(window.order);
            window.order.loadArea = function (area, indicator, params, callback) {
                snapshot();
                return origLoadArea(area, indicator, params, callback);
            };
            window.order._monduLoadAreaWrapped = true;
        }

        bindFieldsetEvents();
        applyMethod(currentMethodCode());
        wrapLoadAreaOnce();

        $(document).off('change.monduFieldsInit', 'input[name="payment[method]"]')
            .on('change.monduFieldsInit', 'input[name="payment[method]"]', function () {
                applyMethod(String($(this).val()));
            });

        if (!window._monduAjaxObserverBound && typeof Ajax !== 'undefined' && Ajax.Responders) {
            window._monduAjaxObserverBound = true;
            Ajax.Responders.register({
                onComplete: function () {
                    setTimeout(function () {
                        bindFieldsetEvents();
                        applyMethod(currentMethodCode());
                        wrapLoadAreaOnce();
                    }, 500);
                }
            });
        }
    };
});
