define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push(
        {
            type: 'mondu',
            component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
        },
        {
            type: 'mondusepa',
            component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
        },
        {
            type: 'monduinstallment',
            component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
        },
        {
            type: 'monduinstallmentbyinvoice',
            component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
        },
        {
            type: 'mondupaynow',
            component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
        },
    );

    return Component.extend({});
});
