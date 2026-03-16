/**
 * Vendor dashboard — shipping label queue interactions.
 */
jQuery(function ($) {

    // -------------------------------------------------------------------------
    // Toast notification helper
    // -------------------------------------------------------------------------
    function ltShowToast(message, type) {
        var bg = (type === 'error') ? '#c62828' : '#2e7d32';
        var $toast = $('<div>')
            .text(message)
            .css({
                position:     'fixed',
                top:          '24px',
                right:        '24px',
                background:   bg,
                color:        '#fff',
                padding:      '14px 22px',
                borderRadius: '6px',
                boxShadow:    '0 4px 16px rgba(0,0,0,0.25)',
                fontSize:     '15px',
                zIndex:       99999,
                maxWidth:     '380px',
                lineHeight:   '1.4',
                opacity:      0,
            })
            .appendTo('body')
            .animate({ opacity: 1 }, 200);

        setTimeout(function () {
            $toast.animate({ opacity: 0 }, 400, function () { $(this).remove(); });
        }, 4000);
    }

    // -------------------------------------------------------------------------
    // Save tracking number and notify customer
    // -------------------------------------------------------------------------
    $(document).on('click', '.lt-save-tracking-btn', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var $card    = $btn.closest('.lt-label-card');
        var $input   = $card.find('.lt-tracking-input');
        var $msg     = $card.find('.lt-tracking-msg');
        var tracking = $.trim($input.val());
        var orderId  = $btn.data('order');
        var vendorId = $btn.data('vendor');
        var nonce    = $btn.data('nonce');
        var notify   = $card.find('.lt-notify-checkbox').is(':checked') ? 1 : 0;

        if (!tracking) {
            $msg.css('color', 'red').text('Please enter a tracking number.');
            return;
        }

        $btn.prop('disabled', true).text('Saving…');
        $msg.css('color', '').text('');

        $.post(ltShipping.ajaxUrl, {
            action:    'lt_save_tracking',
            order_id:  orderId,
            vendor_id: vendorId,
            tracking:  tracking,
            notify:    notify,
            nonce:     nonce,
        })
        .done(function (res) {
            if (res.success) {
                var toastMsg = notify ? 'Tracking saved! Customer has been notified by email.' : 'Tracking saved.';
                ltShowToast(toastMsg);
                setTimeout(function () { window.location.reload(); }, 1200);
            } else {
                $msg.css('color', 'red').text('Error: ' + res.data);
                $btn.prop('disabled', false).text('Save & Notify Customer');
            }
        })
        .fail(function () {
            $msg.css('color', 'red').text('Network error.');
            $btn.prop('disabled', false).text('Save & Notify Customer');
        });
    });

    // -------------------------------------------------------------------------
    // Fetch live rates when vendor clicks "Get Shipping Rates"
    // -------------------------------------------------------------------------
    $(document).on('click', '.lt-fetch-rates-btn', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var orderId  = $btn.data('order');
        var vendorId = $btn.data('vendor');
        var $output  = $('#lt-rates-' + orderId);

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
                $output.empty().append(
                    $('<p>').css('color', 'red').text('Error: ' + String(res.data || 'Unknown error'))
                );
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
    $(document).on('click', '.lt-buy-label-submit', function () {
        $(this).closest('form').find('.lt-buy-label-submit').removeClass('active');
        $(this).addClass('active');
    });

    $(document).on('submit', '.lt-buy-label-form', function (e) {
        e.preventDefault();

        var $form    = $(this);
        var $clicked = $form.find('.lt-buy-label-submit.active');
        if (!$clicked.length) {
            $clicked = $form.find('.lt-buy-label-submit:focus');
        }
        if (!$clicked.length) {
            $clicked = $form.find('.lt-buy-label-submit').last();
        }

        var rateId   = $clicked.val();
        var orderId  = $form.find('input[name="order_id"]').val();
        var vendorId = $form.find('input[name="vendor_id"]').val();
        var nonce    = $form.find('input[name="lt_nonce"]').val();
        var notify   = $form.find('input[name="lt_notify"]').is(':checked') ? 1 : 0;
        var $msg     = $form.find('.lt-buy-label-msg');

        if (!rateId) {
            $msg.css('color', 'red').text('Could not determine selected rate. Please try again.');
            return;
        }

        $clicked.prop('disabled', true).text('Purchasing…');
        $msg.css('color', '').text('Processing…');

        $.post(ltShipping.ajaxUrl, {
            action:    'lt_buy_label',
            order_id:  orderId,
            vendor_id: vendorId,
            rate_id:   rateId,
            notify:    notify,
            lt_nonce:  nonce,
        })
        .done(function (res) {
            if (res.success) {
                var trackingNum = String(res.data.tracking_num || '');
                var labelUrl    = String(res.data.label_url    || '');
                var msg = 'Label purchased! Tracking: ' + trackingNum + (notify ? ' — Customer notified.' : '');
                ltShowToast(msg);
                // Replace form with download button + tracking — use DOM nodes to avoid XSS
                var $wrap = $('<div>');
                var $info = $('<div>').css({ color: 'green', marginBottom: '10px' });
                $info.append($('<strong>').text('\u2713 Label purchased! '));
                $info.append(document.createTextNode('Tracking: '));
                $info.append($('<strong>').text(trackingNum));
                $wrap.append($info);
                var $dl = $('<a>')
                    .attr({ href: labelUrl, target: '_blank', rel: 'noopener noreferrer' })
                    .addClass('dokan-btn dokan-btn-sm dokan-btn-theme')
                    .css('display', 'inline-block')
                    .text('\uD83D\uDCCE Download & Print Label');
                $wrap.append($dl);
                $form.replaceWith($wrap);
            } else {
                $msg.css('color', 'red').text('Error: ' + res.data);
                $clicked.prop('disabled', false).text('Buy Label');
            }
        })
        .fail(function () {
            $msg.css('color', 'red').text('Network error. Please try again.');
            $clicked.prop('disabled', false).text('Buy Label');
        });
    });

    // -------------------------------------------------------------------------
    // Connect own Shippo account — toggle provider fields
    // -------------------------------------------------------------------------
    $(document).on('change', 'input[name="lt_provider_type"]', function () {
        var val = $(this).val();
        $('#lt-shippo-fields').toggle(val === 'shippo');
        $('#lt-ss-fields').toggle(val === 'shipstation');
    });

    $(document).on('submit', '#lt-connect-account-form', function (e) {
        e.preventDefault();

        var $form  = $(this);
        var $msg   = $('#lt-connect-msg');
        var $btn   = $('#lt-connect-submit');
        var type   = $form.find('input[name="lt_provider_type"]:checked').val();
        var apiKey = type === 'shipstation'
            ? $form.find('input[name="lt_ss_key"]').val()
            : $form.find('input[name="lt_api_key"]').val();
        var secret = $form.find('input[name="lt_ss_secret"]').val();
        var nonce  = $form.find('input[name="lt_creds_nonce"]').val();

        if (!apiKey) {
            $msg.css('color', 'red').text('API key is required.');
            return;
        }

        $btn.prop('disabled', true).text('Verifying…');
        $msg.css('color', '').text('Checking credentials…');

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

    $(document).on('click', '#lt-remove-account', function (e) {
        e.preventDefault();
        if (!confirm('Disconnect your Shippo account?')) { return; }

        $.post(ltShipping.ajaxUrl, {
            action: 'lt_remove_vendor_credentials',
            nonce:  $(this).data('nonce'),
        })
        .done(function (res) {
            if (res.success) { window.location.reload(); }
            else { alert('Error: ' + res.data); }
        });
    });

    // -------------------------------------------------------------------------
    // Mark order as already shipped (dismiss without tracking)
    // -------------------------------------------------------------------------
    $(document).on('click', '.lt-mark-shipped-btn', function (e) {
        e.preventDefault();

        var $btn     = $(this);
        var $msg     = $btn.siblings('.lt-mark-shipped-msg');
        var orderId  = $btn.data('order');
        var vendorId = $btn.data('vendor');
        var nonce    = $btn.data('nonce');

        if (!confirm('Mark Order #' + orderId + ' as already shipped? It will be removed from this list.')) {
            return;
        }

        $btn.prop('disabled', true).text('Saving…');

        $.post(ltShipping.ajaxUrl, {
            action:    'lt_mark_shipped',
            order_id:  orderId,
            vendor_id: vendorId,
            nonce:     nonce,
        })
        .done(function (res) {
            if (res.success) {
                window.location.reload();
            } else {
                $msg.css('color', 'red').text('Error: ' + res.data);
                $btn.prop('disabled', false).text('✓ Already Shipped (no tracking)');
            }
        })
        .fail(function () {
            $msg.css('color', 'red').text('Network error.');
            $btn.prop('disabled', false).text('✓ Already Shipped (no tracking)');
        });
    });

});
