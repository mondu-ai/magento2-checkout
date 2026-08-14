/**
 * Shared visibility/required-state logic for the Mondu admin order-create fields.
 *
 * Both entry points (`mondu-admin-init` on page load and `mondu-fields` on the
 * fieldset itself, re-created by every AJAX reload of the billing_method area)
 * delegate here so the rules live in one place.
 *
 * Config comes from PHP (\Mondu\Mondu\Model\Payment\AsyncOrderFields):
 *   requiredByMethod  {Object.<string,string[]>}  always-mandatory fields per method
 *   optionalByMethod  {Object.<string,string[]>}  shown but never enforced
 *   ownerFields       {string[]}                  owner.* fields
 *   categoryField     {string}                    legal_form_category field name
 *   soleTraderValue   {string}                    category that makes owners mandatory
 */
define([
    'jquery'
], function ($) {
    'use strict';

    var FIELDSET_SELECTOR = '#mondu-order-create-fields';

    if (typeof window.MonduAdminFieldCache === 'undefined') {
        window.MonduAdminFieldCache = {};
    }
    var cache = window.MonduAdminFieldCache;

    function config() {
        return window.MonduFieldsConfig || {};
    }

    function currentMethodCode() {
        var $checked = $('input[name="payment[method]"]:checked');
        return $checked.length ? String($checked.val()) : '';
    }

    function fieldId(fieldName) {
        return fieldName;
    }

    function currentCategory() {
        var cfg = config(),
            id = fieldId(cfg.categoryField || ''),
            $select = id ? $('#' + id) : $();

        if ($select.length) {
            return String($select.val() || '');
        }

        return String(cache[id] || '');
    }

    function hideAll($fs) {
        $fs.hide();
        $fs.find('input, select').prop('required', false);
        $fs.find('[data-mondu-field]').removeClass('_required');
    }

    function restoreFromCache($fs) {
        $fs.find('input, select').each(function () {
            if (this.id && Object.prototype.hasOwnProperty.call(cache, this.id)) {
                $(this).val(cache[this.id]);
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

    /**
     * Applies visibility + required flags for the currently selected method.
     */
    function apply() {
        var cfg = config(),
            requiredByMethod = cfg.requiredByMethod || {},
            optionalByMethod = cfg.optionalByMethod || {},
            ownerFields = cfg.ownerFields || [],
            $fs = $(FIELDSET_SELECTOR),
            code = currentMethodCode(),
            required,
            visible;

        if (!$fs.length) {
            return;
        }

        if (!Object.prototype.hasOwnProperty.call(requiredByMethod, code)) {
            hideAll($fs);
            return;
        }

        restoreFromCache($fs);

        required = (requiredByMethod[code] || []).slice();
        visible = required.concat(optionalByMethod[code] || []);

        // The owner object is mandatory only for sole traders (einzelunternehmen),
        // and only for methods that offer the legal form category at all.
        if (visible.indexOf(cfg.categoryField) !== -1 && currentCategory() === cfg.soleTraderValue) {
            required = required.concat(ownerFields);
            visible = visible.concat(ownerFields);
        }

        if (!visible.length) {
            hideAll($fs);
            return;
        }

        $fs.show();
        $fs.find('[data-mondu-field]').each(function () {
            var $wrap = $(this),
                fieldName = $wrap.data('mondu-field'),
                isVisible = visible.indexOf(fieldName) !== -1,
                isRequired = required.indexOf(fieldName) !== -1;

            $wrap.toggle(isVisible);
            $wrap.toggleClass('_required', isRequired);
            $wrap.find('input, select').prop('required', isVisible && isRequired);
        });
    }

    /**
     * Binds the per-fieldset listeners once per DOM instance.
     */
    function bind() {
        var $fs = $(FIELDSET_SELECTOR);

        if (!$fs.length || $fs.data('monduBound')) {
            return;
        }
        $fs.data('monduBound', true);

        $fs.on('change input', 'input, select', function () {
            if (this.id === 'mondu_iban') {
                this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            }
            if (this.id) {
                cache[this.id] = $(this).val();
            }
            if (this.id === fieldId(config().categoryField || '')) {
                apply();
            }
        });
    }

    /**
     * Keeps values across the Prototype AJAX reloads of the billing_method block.
     */
    function wrapLoadAreaOnce() {
        if (!window.order || typeof window.order.loadArea !== 'function') {
            return;
        }
        if (window.order._monduLoadAreaWrapped) {
            return;
        }
        var origLoadArea = window.order.loadArea.bind(window.order);
        window.order.loadArea = function (area, indicator, params, callback) {
            snapshot();
            return origLoadArea(area, indicator, params, callback);
        };
        window.order._monduLoadAreaWrapped = true;
    }

    function bindMethodChangeOnce(namespace) {
        $(document).off('change.' + namespace, 'input[name="payment[method]"]')
            .on('change.' + namespace, 'input[name="payment[method]"]', apply);
    }

    /**
     * Magento admin uses Prototype's Ajax.Request, not jQuery.ajax, so the
     * fieldset is replaced without any jQuery event firing.
     */
    function bindAjaxObserverOnce() {
        if (window._monduAjaxObserverBound || typeof Ajax === 'undefined' || !Ajax.Responders) {
            return;
        }
        window._monduAjaxObserverBound = true;
        Ajax.Responders.register({
            onComplete: function () {
                setTimeout(function () {
                    bind();
                    apply();
                    wrapLoadAreaOnce();
                }, 500);
            }
        });
    }

    return {
        setConfig: function (cfg) {
            if (cfg && cfg.requiredByMethod) {
                window.MonduFieldsConfig = cfg;
            }
        },
        apply: apply,
        bind: bind,
        snapshot: snapshot,
        wrapLoadAreaOnce: wrapLoadAreaOnce,
        bindMethodChangeOnce: bindMethodChangeOnce,
        bindAjaxObserverOnce: bindAjaxObserverOnce
    };
});
