/* CodeConfig Demo Manager — Front-end form logic */
(function ($) {
    'use strict';

    const wrap    = $('#ccdemo-wrap');
    const step1   = $('#ccdemo-step-1');
    const step2   = $('#ccdemo-step-2');
    const step3   = $('#ccdemo-step-3');
    const form    = $('#ccdemo-form');
    const errBox  = $('#ccdemo-error');
    const submit  = $('#ccdemo-submit');
    const btnText = submit.find('.ccdemo-btn-text');
    const spinner = submit.find('.ccdemo-spinner');

    let selectedProduct = '';
    let selectedLabel   = '';

    /* ── Step 1 → 2: product selection ── */
    step1.on('click', '.ccdemo-product-btn', function () {
        selectedProduct = $(this).data('product');
        selectedLabel   = $(this).text().trim();

        step1.addClass('ccdemo-hidden');
        step2.removeClass('ccdemo-hidden');

        $('#ccdemo-product-input').val(selectedProduct);
        $('#ccdemo-product-label').text(selectedLabel);
        step2.find('input[name="name"]').trigger('focus');
    });

    /* ── Back button ── */
    $('#ccdemo-back').on('click', function () {
        step2.addClass('ccdemo-hidden');
        step1.removeClass('ccdemo-hidden');
        errBox.addClass('ccdemo-hidden').text('');
    });

    /* ── Form submit ── */
    form.on('submit', function (e) {
        e.preventDefault();
        errBox.addClass('ccdemo-hidden').text('');

        // Client-side validation
        const name    = $.trim($('#ccdemo-name').val());
        const email   = $.trim($('#ccdemo-email').val());

        if (!name || !email) {
            showError('Please fill in your name and email address.');
            return;
        }

        if (!isValidEmail(email)) {
            showError('Please enter a valid email address.');
            return;
        }

        setLoading(true);

        $.ajax({
            url:      CCDemoAjax.ajaxurl,
            type:     'POST',
            dataType: 'json',
            data: {
                action:   'ccdemo_request',
                nonce:    CCDemoAjax.nonce,
                name:     name,
                email:    email,
                company:  $.trim($('#ccdemo-company').val()),
                phone:    $.trim($('#ccdemo-phone').val()),
                product:  selectedProduct,
            },
            success: function (res) {
                if (res.success) {
                    step2.addClass('ccdemo-hidden');
                    step3.removeClass('ccdemo-hidden');
                    $('#ccdemo-success-msg').text(res.data.message || 'Check your inbox for the demo link!');
                } else {
                    showError(res.data.message || 'Something went wrong. Please try again.');
                }
            },
            error: function () {
                showError('Network error. Please check your connection and try again.');
            },
            complete: function () {
                setLoading(false);
            }
        });
    });

    /* ── Helpers ── */
    function showError(msg) {
        errBox.text(msg).removeClass('ccdemo-hidden');
        wrap[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function setLoading(on) {
        submit.prop('disabled', on);
        btnText.toggleClass('ccdemo-hidden', on);
        spinner.toggleClass('ccdemo-hidden', !on);
    }

    function isValidEmail(email) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    }

}(jQuery));
