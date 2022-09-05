define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'mondu',
                component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
            }
        );
        rendererList.push(
            {
                type: 'mondusepa',
                component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
            }
        );
        rendererList.push(
            {
                type: 'monduinstallment',
                component: 'Mondu_Mondu/js/view/payment/method-renderer/mondu'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({});
    }
);
