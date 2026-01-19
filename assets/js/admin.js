(function ($) {
    'use strict';

    // Employee sync functionality
    $(document).ready(function () {

        // Handle sync button click
        $('#oes-sync-button').on('click', function (e) {
            e.preventDefault();

            if (!confirm(oes_ajax.i18n.confirm_sync)) {
                return;
            }

            var $button = $(this);
            var $progress = $('#oes-sync-progress');
            var $status = $('#oes-sync-status');
            var $results = $('#oes-sync-results');

            // Disable button and show progress
            $button.prop('disabled', true);
            $progress.show();
            $results.hide();

            // Update status
            $status.text(oes_ajax.i18n.syncing);

            // Make AJAX request
            $.ajax({
                url: oes_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'oes_sync_employees',
                    nonce: oes_ajax.nonce
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        $status.text(oes_ajax.i18n.success);

                        // Show results
                        $results.html(response.data.summary_html).show();

                        // Update status cards if they exist
                        if (response.data.results) {
                            var total = response.data.results.inserted + response.data.results.updated;
                            $('.oes-number').text(total);
                            $('.oes-date').text(new Date().toLocaleString());
                        }

                        // Reload page after 3 seconds to show updated data
                        setTimeout(function () {
                            location.reload();
                        }, 3000);
                    } else {
                        $status.text(oes_ajax.i18n.error + ': ' + response.data.message);
                    }
                },
                error: function (xhr, status, error) {
                    $status.text(oes_ajax.i18n.error + ': ' + error);
                },
                complete: function () {
                    // Re-enable button after 5 seconds
                    setTimeout(function () {
                        $button.prop('disabled', false);
                    }, 5000);
                }
            });
        });

        // Handle token validation
        $('#oes-validate-token').on('click', function () {
            var $button = $(this);
            var $result = $('#oes-token-validation-result');
            var token = $('#oes_api_token').val();

            if (!token) {
                $result.text('Please enter a token first.').css('color', 'orange');
                return;
            }

            $button.prop('disabled', true);
            $result.text(oes_ajax.i18n.validate_token).css('color', 'blue');

            $.ajax({
                url: oes_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'oes_validate_token',
                    nonce: oes_ajax.nonce,
                    token: token
                },
                dataType: 'json',
                success: function (response) {
                    if (response.success) {
                        $result.text(oes_ajax.i18n.token_valid).css('color', 'green');
                    } else {
                        $result.text(oes_ajax.i18n.token_invalid).css('color', 'red');
                    }
                },
                error: function () {
                    $result.text('Validation request failed.').css('color', 'red');
                },
                complete: function () {
                    $button.prop('disabled', false);
                }
            });
        });

        // Toggle token visibility (optional enhancement)
        $('#oes_api_token').after(
            '<span class="dashicons dashicons-visibility" style="margin-left: 5px; cursor: pointer; vertical-align: middle;" id="oes-toggle-token"></span>'
        );

        $('#oes-toggle-token').on('click', function () {
            var $input = $('#oes_api_token');
            var $icon = $(this);

            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
            } else {
                $input.attr('type', 'password');
                $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
            }
        });
    });

})(jQuery);