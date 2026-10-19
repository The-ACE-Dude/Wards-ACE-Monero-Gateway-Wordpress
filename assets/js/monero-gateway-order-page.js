/*
 * Monero Payment Page
 * Based on Ryo Currency Project (c) 2018
 */
function monero_showNotification(message, type) {
    type = type || 'success';
    var toast = jQuery('<div class="' + type + '"><span>' + message + '</span></div>');
    jQuery('#monero_toast').append(toast);
    toast.animate({ "right": "12px" }, "fast");
    setTimeout(function() {
        toast.animate({ "right": "-400px" }, "fast", function() {
            toast.remove();
        });
    }, 3500);
}

function monero_showQR(show) {
    if (typeof show === 'undefined') show = true;
    jQuery('#monero_qr_code_container').toggle(show && !monero_details.hold);
}

function monero_showWalletPrompt() {
    if(monero_details.hold) return;
    var $ = jQuery;
    if($('#monero_wallet_prompt').length) return;
    var prompt = $(
        '<div id="monero_wallet_prompt" class="xmr-wallet-prompt">' +
            '<span>Open in your Monero wallet?</span>' +
            '<a class="xmr-prompt-open" href="' + monero_details.qrcode_uri + '">Open Wallet</a>' +
            '<button type="button" class="xmr-prompt-dismiss">&times;</button>' +
        '</div>'
    );
    $('#monero_toast').append(prompt);
    prompt.animate({"right": "12px"}, "fast");
    prompt.find('.xmr-prompt-dismiss').on('click', function() {
        prompt.animate({"right": "-400px"}, "fast", function() { prompt.remove(); });
    });
    setTimeout(function() {
        if(prompt.parent().length) {
            prompt.animate({"right": "-400px"}, "fast", function() { prompt.remove(); });
        }
    }, 8000);
}

function monero_fetchDetails() {
    var data = {
        '_': jQuery.now(),
        'order_id': monero_details.order_id,
        // Guests prove the order is theirs with its key.
        'key': typeof monero_order_key !== 'undefined' ? monero_order_key : ''
    };
    jQuery.get(monero_ajax_url, data, function(response) {
        if (typeof response.error !== 'undefined') {
            console.log(response.error);
        } else {
            monero_details = response;
            monero_updateDetails();
        }
    });
}

function monero_updateSteps(status) {
    var $ = jQuery;
    var steps = $('.xmr-step');
    var lines = $('.xmr-step-line');

    // Reset all
    steps.removeClass('active done error');
    lines.removeClass('active done');

    var waitStep = steps.filter('[data-step="waiting"]');
    var recvStep = steps.filter('[data-step="received"]');
    var confStep = steps.filter('[data-step="confirmed"]');
    var line1 = lines.eq(0);
    var line2 = lines.eq(1);

    switch(status) {
        case 'unpaid':
            waitStep.addClass('active');
            break;
        case 'partial':
            waitStep.addClass('active');
            break;
        case 'paid':
            waitStep.addClass('done');
            line1.addClass('done');
            recvStep.addClass('done');
            line2.addClass('active');
            confStep.addClass('active');
            break;
        case 'confirmed':
            waitStep.addClass('done');
            line1.addClass('done');
            recvStep.addClass('done');
            line2.addClass('done');
            confStep.addClass('done');
            break;
        case 'expired':
        case 'expired_partial':
            waitStep.addClass('error');
            break;
    }
}

function monero_updateDetails() {
    var $ = jQuery;
    var details = monero_details;

    // Update step tracker
    monero_updateSteps(details.status);

    // Update status messages
    $('#monero_payment_messages').children().hide();
    switch(details.status) {
        case 'unpaid':
            $('.monero_payment_unpaid').show();
            $('.monero_payment_expire_time').html(details.order_expires);
            break;
        case 'partial':
            $('.monero_payment_partial').show();
            $('.monero_payment_expire_time').html(details.order_expires);
            break;
        case 'paid':
            $('.monero_payment_paid').show();
            $('.monero_confirm_time').html(details.time_to_confirm);
            break;
        case 'confirmed':
            $('.monero_payment_confirmed').show();
            break;
        case 'expired':
            $('.monero_payment_expired').show();
            break;
        case 'expired_partial':
            $('.monero_payment_expired_partial').show();
            break;
    }

    $('.monero_payment_review').text(details.warning || '').toggle(!!details.hold);
    $('.xmr-payment-card').toggle(!details.hold);
    if(details.hold) {
        $('#monero_payment_messages').children().not('.monero_payment_review').hide();
        $('#monero_wallet_prompt').remove();
        monero_showQR(false);
    }

    // Update payment data
    $('#monero_exchange_rate').html('1 XMR = ' + details.rate_formatted + ' ' + details.currency);
    $('#monero_total_amount').html(details.amount_total_formatted);
    $('#monero_total_paid').html(details.amount_paid_formatted);
    $('#monero_total_due').html(details.amount_due_formatted);
    $('#monero_integrated_address').html(details.integrated_address);

    // Update Open in Wallet link
    if(details.qrcode_uri) {
        $('#monero_open_wallet').attr('href', details.qrcode_uri);
        if(!details.hold && (details.status === 'unpaid' || details.status === 'partial')) {
            $('.xmr-wallet-action').show();
        } else {
            $('.xmr-wallet-action').hide();
        }
    }

    // Discount banner with savings
    if(details.discount && details.discount > 0) {
        $('#xmr_discount_pct').text(details.discount);
        if(details.fiat_total && details.fiat_savings) {
            var sym = details.fiat_currency_symbol || '$';
            $('#xmr_original_price').html(sym + details.fiat_total.toFixed(2));
            $('#xmr_discounted_price').html(sym + details.fiat_discounted.toFixed(2));
            $('#xmr_savings_amount').html(sym + details.fiat_savings.toFixed(2));
        }
        $('#xmr_discount_banner').show();
    }

    // QR code
    if(monero_show_qr && !details.hold) {
        var qr = $('#monero_qr_code').html('');
        new QRCode(qr.get(0), details.qrcode_uri);
    }

    // Transactions table
    if(details.txs.length) {
        $('#monero_tx_table').show();
        $('#monero_tx_none').hide();
        $('#monero_tx_table tbody').html('');
        for(var i = 0; i < details.txs.length; i++) {
            var tx = details.txs[i];
            var height = tx.height == 0 ? 'Pending' : tx.height;
            var row =
                '<tr>' +
                '<td><a href="' + monero_explorer_url + '/tx/' + tx.txid + '" target="_blank" rel="noopener">' + tx.txid + '</a></td>' +
                '<td>' + height + '</td>' +
                '<td>' + tx.amount_formatted + ' XMR</td>' +
                '</tr>';
            $('#monero_tx_table tbody').append(row);
        }
    } else {
        $('#monero_tx_table').hide();
        $('#monero_tx_none').show();
    }

    // State change notifications
    var new_txs = details.txs;
    var old_txs = monero_order_state.txs;
    if(new_txs.length != old_txs.length) {
        for(var i = 0; i < new_txs.length; i++) {
            var is_new_tx = true;
            for(var j = 0; j < old_txs.length; j++) {
                if(new_txs[i].txid == old_txs[j].txid && new_txs[i].amount == old_txs[j].amount) {
                    is_new_tx = false;
                    break;
                }
            }
            if(is_new_tx) {
                monero_showNotification('Transaction received for ' + new_txs[i].amount_formatted + ' XMR');
            }
        }
    }

    if(details.status != monero_order_state.status) {
        switch(details.status) {
            case 'paid':
                monero_showNotification('Payment received in full!');
                break;
            case 'confirmed':
                monero_showNotification('Payment confirmed! Thank you!');
                break;
            case 'expired':
            case 'expired_partial':
                monero_showNotification('Order has expired', 'error');
                break;
        }
    }

    monero_order_state = {
        status: monero_details.status,
        txs: monero_details.txs
    };
}

jQuery(document).ready(function($) {
    if (typeof monero_details !== 'undefined') {
        monero_order_state = {
            status: monero_details.status,
            txs: monero_details.txs
        };
        setInterval(monero_fetchDetails, 10000);
        monero_updateDetails();
        new ClipboardJS('.clipboard').on('success', function(e) {
            e.clearSelection();
            if(e.trigger.disabled) return;
            switch(e.trigger.getAttribute('data-clipboard-target')) {
                case '#monero_integrated_address':
                    monero_showNotification('Copied destination address!');
                    // Offer to open wallet after copying address
                    if(!monero_details.hold && monero_details.qrcode_uri && (monero_details.status === 'unpaid' || monero_details.status === 'partial')) {
                        setTimeout(function() {
                            monero_showWalletPrompt();
                        }, 800);
                    }
                    break;
                case '#monero_total_due':
                    monero_showNotification('Copied total amount due!');
                    break;
            }
            e.clearSelection();
        });
    }
});