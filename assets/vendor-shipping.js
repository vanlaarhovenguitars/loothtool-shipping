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

});
