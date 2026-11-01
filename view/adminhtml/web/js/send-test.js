/**
 * @copyright NARBOWEB SRL
 * @license   MIT
 */
define([
    'jquery',
    'mage/translate'
], function ($, $t) {
    'use strict';

    /**
     * "Send a Test Text": posts the typed test number and shows SMSTunnel's answer under the button.
     *
     * @param {Object} config
     * @param {HTMLElement} element
     */
    return function (config, element) {
        var $root = $(element),
            $button = $root.find('button'),
            $result = $root.find('.smstunnel-send-test-result');

        /**
         * Show the outcome.
         *
         * @param {Boolean} success
         * @param {String} message
         */
        function show(success, message) {
            $result.text(message).css('color', success ? '#185b00' : '#e22626');
        }

        $button.on('click', function () {
            $button.prop('disabled', true);
            $result.text($t('Sending...')).css('color', '');

            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                showLoader: true,
                data: {
                    'form_key': window.FORM_KEY,
                    number: $(config.numberField).val() || '',
                    website: config.website,
                    store: config.store
                }
            }).done(function (response) {
                show(Boolean(response && response.success), response && response.message ?
                    response.message : $t('The test text could not be sent.'));
            }).fail(function () {
                show(false, $t('The test text could not be sent. Please reload the page and try again.'));
            }).always(function () {
                $button.prop('disabled', false);
            });
        });
    };
});
