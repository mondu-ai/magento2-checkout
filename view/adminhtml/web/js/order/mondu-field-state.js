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
            var cached;

            if (!this.id || !Object.prototype.hasOwnProperty.call(cache, this.id)) {
                return;
            }

            // A cached value can outlive its option: the net term list is rebuilt
            // per billing country, so a term picked for the previous country may
            // be gone. Drop it and keep the freshly rendered default.
            if (this.tagName === 'SELECT') {
                cached = String(cache[this.id]);
                if (!Array.prototype.some.call(this.options, function (option) {
                    return option.value === cached;
                })) {
                    delete cache[this.id];
                    return;
                }
            }

            $(this).val(cache[this.id]);
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
     * Billing country currently selected on the order-create form.
     */
    function currentBillingCountry() {
        var id = config().billingCountryFieldId,
            $select = id ? $('#' + id) : $();

        return $select.length ? String($select.val() || '').toUpperCase() : '';
    }

    /**
     * Mondu requires a registration_id for every buyer except German ones, and
     * the rule is the same for all payment methods.
     */
    function isRegistrationIdRequired() {
        var country = currentBillingCountry(),
            optional = config().registrationIdOptionalCountries || [];

        return country !== '' && optional.indexOf(country) === -1;
    }

    /**
     * Points the admin at the register the number comes from (HRB, KVK, KBO, …).
     */
    function updateRegistrationIdHint($fs) {
        var cfg = config(),
            hints = cfg.registrationIdHints || {},
            $note = $fs.find('[data-mondu-registration-hint]');

        if (!$note.length) {
            return;
        }

        $note.text(hints[currentBillingCountry()] || cfg.registrationIdFallbackHint || '');
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

        if (cfg.registrationIdField
            && visible.indexOf(cfg.registrationIdField) !== -1
            && isRegistrationIdRequired()
        ) {
            required = required.concat([cfg.registrationIdField]);
        }
        updateRegistrationIdHint($fs);

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
     * The registration_id rule and its hint follow the billing country, which
     * lives outside the fieldset and survives the AJAX reloads.
     */
    function bindBillingCountryChangeOnce(namespace) {
        var id = config().billingCountryFieldId;

        if (!id) {
            return;
        }

        $(document).off('change.' + namespace, '#' + id)
            .on('change.' + namespace, '#' + id, apply);
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
        bindBillingCountryChangeOnce: bindBillingCountryChangeOnce,
        bindAjaxObserverOnce: bindAjaxObserverOnce
    };
});
