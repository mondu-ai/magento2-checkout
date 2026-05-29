define([
    'jquery',
    'mage/translate',
    'mage/storage'
], function ($, $t, storage) {
    'use strict';

    return function (config, element) {
        $(element).on('click', function (e) {
            e.preventDefault();

            var consent = $('#mondu-gdpr-consent');
            if (!consent.is(':checked')) {
                alert($t('Please accept the data processing consent before applying.'));
                return;
            }

            var $btn = $(this);
            var applyUrl = $btn.data('apply-url');

            $btn.prop('disabled', true).addClass('disabled');
            $('body').trigger('processStart');

            $.ajax({
                url: applyUrl,
                type: 'POST',
                dataType: 'json',
                data: {
                    gdpr_consent: 1
                },
                success: function (response) {
                    if (response.success && response.hosted_page_url) {
                        window.location.href = response.hosted_page_url;
                    } else {
                        $('body').trigger('processStop');
                        $btn.prop('disabled', false).removeClass('disabled');
                        alert(response.message || $t('An error occurred. Please try again.'));
                    }
                },
                error: function () {
                    $('body').trigger('processStop');
                    $btn.prop('disabled', false).removeClass('disabled');
                    alert($t('An error occurred. Please try again.'));
                }
            });
        });
    };
});
