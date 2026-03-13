/**
 * Vendor dashboard — fetch rates and buy labels via AJAX.
 */
jQuery(function ($) {

    // -------------------------------------------------------------------------
    // Fetch live rates when vendor clicks "Get Shipping Rates"
    // -------------------------------------------------------------------------
    $(document).on('click', '.lt-fetch-rates-btn', function (e) {
        e.preventDefault();

        var $btn      = $(this);
        var orderId   = $btn.data('order');
        var vendorId  = $btn.data('vendor');
        var $output   = $('#lt-rates-' + orderId);

        $btn.prop('disabled', true).text('Fetching rates…');
        $output.html('');

        $.post(ltShipping.ajaxUrl, {
            action:    'lt_fetch_order_rates',
            order_id:  orderId,
            vendor_id: vendorId,
            lt_nonce:  ltShipping.nonce,
        })
        .done(function (res) {
            if (res.success) {
                $output.html(res.data.html);
                $btn.hide();
            } else {
                $output.html('<p style="color:red;">Error: ' + res.data + '</p>');
                $btn.prop('disabled', false).text('Get Shipping Rates');
            }
        })
        .fail(function () {
            $output.html('<p style="color:red;">Network error. Please try again.</p>');
            $btn.prop('disabled', false).text('Get Shipping Rates');
        });
    });

    // -------------------------------------------------------------------------
    // Buy label when vendor clicks a "Buy Label" button in the rate table
    // -------------------------------------------------------------------------
    $(document).on('submit', '.lt-buy-label-form', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var $clicked = $form.find('button[name="rate_id"]:focus, button[name="rate_id"].active');

        // Fallback: detect which button triggered submit.
        if (!$clicked.length) {
            $clicked = $form.find('button[name="rate_id"]').filter(function () {
                return $(this).is(':focus');
            });
        }

        var rateId   = $clicked.val() || $form.find('button[name="rate_id"]:last').val();
        var orderId  = $form.find('input[name="order_id"]').val();
        var vendorId = $form.find('input[name="vendor_id"]').val();
        var nonce    = $form.find('input[name="lt_nonce"]').val();

        if (!rateId) {
            alert('Could not determine selected rate. Please try again.');
            return;
        }

        $clicked.prop('disabled', true).text('Purchasing…');

        $.post(ltShipping.ajaxUrl, {
            action:    'lt_buy_label',
            order_id:  orderId,
            vendor_id: vendorId,
            rate_id:   rateId,
            lt_nonce:  nonce,
        })
        .done(function (res) {
            if (res.success) {
                var html = '<div style="color:green;margin-top:12px;">'
                    + '<strong>&#10003; Label purchased!</strong><br>'
                    + 'Tracking: <strong>' + res.data.tracking_num + '</strong><br>'
                    + '$' + res.data.amount_charged + ' ' + res.data.currency + ' deducted from your balance.<br>'
                    + '<a href="' + res.data.label_url + '" target="_blank">Download Label (PDF)</a>'
                    + '</div>';
                $form.replaceWith(html);
            } else {
                alert('Error: ' + res.data);
                $clicked.prop('disabled', false).text('Buy Label');
            }
        })
        .fail(function () {
            alert('Network error. Please try again.');
            $clicked.prop('disabled', false).text('Buy Label');
        });
    });

    // Track which Buy Label button was most recently clicked (for the submit handler above).
    $(document).on('mousedown', '.lt-buy-label-submit', function () {
        $('.lt-buy-label-submit').removeClass('active');
        $(this).addClass('active');
    });

    // -------------------------------------------------------------------------
    // Connect own shipping account — toggle Shippo / ShipStation fields
    // -------------------------------------------------------------------------
    $(document).on('change', 'input[name="lt_provider_type"]', function () {
        var val = $(this).val();
        $('#lt-shippo-fields').toggle(val === 'shippo');
        $('#lt-ss-fields').toggle(val === 'shipstation');
    });

    // Connect account form submit
    $(document).on('submit', '#lt-connect-account-form', function (e) {
        e.preventDefault();

        var $form   = $(this);
        var $msg    = $('#lt-connect-msg');
        var $btn    = $('#lt-connect-submit');
        var type    = $form.find('input[name="lt_provider_type"]:checked').val();
        var apiKey  = type === 'shipstation'
            ? $form.find('input[name="lt_ss_key"]').val()
            : $form.find('input[name="lt_api_key"]').val();
        var secret  = $form.find('input[name="lt_ss_secret"]').val();
        var nonce   = $form.find('input[name="lt_creds_nonce"]').val();

        if (!apiKey) {
            $msg.css('color', 'red').text('API key is required.');
            return;
        }

        $btn.prop('disabled', true).text('Verifying…');
        $msg.css('color', '').text('');

        $.post(ltShipping.ajaxUrl, {
            action:           'lt_save_vendor_credentials',
            lt_provider_type: type,
            lt_api_key:       apiKey,
            lt_ss_key:        apiKey,
            lt_ss_secret:     secret,
            lt_creds_nonce:   nonce,
        })
        .done(function (res) {
            if (res.success) {
                $msg.css('color', 'green').text('');
                // Reload page to show connected state.
                window.location.reload();
            } else {
                $msg.css('color', 'red').text('Error: ' + res.data);
                $btn.prop('disabled', false).text('Connect & Verify');
            }
        })
        .fail(function () {
            $msg.css('color', 'red').text('Network error. Please try again.');
            $btn.prop('disabled', false).text('Connect & Verify');
        });
    });

    // Disconnect account
    $(document).on('click', '#lt-remove-account', function (e) {
        e.preventDefault();

        if (!confirm('Disconnect your shipping account? You will use the platform account instead.')) {
            return;
        }

        var nonce = $(this).data('nonce');

        $.post(ltShipping.ajaxUrl, {
            action: 'lt_remove_vendor_credentials',
            nonce:  nonce,
        })
        .done(function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                alert('Error: ' + res.data);
            }
        });
    });

    // Success message: update balance line if vendor pays direct
    // (handled inline in the done handler for buy_label above)

    // -------------------------------------------------------------------------
    // Label format preference — auto-save on radio change
    // -------------------------------------------------------------------------
    $(document).on('change', '.lt-format-radio', function () {
        var format = $(this).val();
        var nonce  = $('input[name="lt_format_nonce"]').val();
        var $msg   = $('#lt-format-msg');

        $msg.text('Saving…');

        $.post(ltShipping.ajaxUrl, {
            action:           'lt_save_label_format',
            lt_label_format:  format,
            lt_format_nonce:  nonce,
        })
        .done(function (res) {
            if (res.success) {
                $msg.text(format === '' ? 'Using platform default.' : 'Saved.');
                setTimeout(function () { $msg.text(''); }, 2000);
            } else {
                $msg.css('color', 'red').text('Error saving preference.');
            }
        })
        .fail(function () {
            $msg.css('color', 'red').text('Network error.');
        });
    });

});
