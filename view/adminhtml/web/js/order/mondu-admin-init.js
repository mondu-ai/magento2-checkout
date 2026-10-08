/**
 * Page-load entry point for the Mondu admin order-create fields.
 *
 * Receives the field registry from PHP (see mondu_fields_init.phtml) and hands
 * it to the shared state module.
 */
define([
    'Mondu_Mondu/js/order/mondu-field-state'
], function (fieldState) {
    'use strict';

    return function (config) {
        fieldState.setConfig(config);
        fieldState.bind();
        fieldState.apply();
        fieldState.wrapLoadAreaOnce();
        fieldState.bindMethodChangeOnce('monduFieldsInit');
        fieldState.bindBillingCountryChangeOnce('monduFieldsInit');
        fieldState.bindAjaxObserverOnce();
    };
});
