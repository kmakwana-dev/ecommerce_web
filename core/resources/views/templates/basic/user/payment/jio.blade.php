{{--
    FILE LOCATION:
    core/resources/views/templates/basic/user/payment/jio.blade.php

    This view is rendered by PaymentController::depositConfirm() when alias = 'jio'.
    Variables available:
      $data    — decoded JSON from ProcessController::process()
      $deposit — Deposit model instance
--}}
@extends('Template::layouts.checkout')

@section('blade')
<div class="card custom--card">
    <div class="card-body text-center py-4">

        <div class="mb-2">
            <span class="badge bg-success px-3 py-2" style="font-size:0.8rem; letter-spacing:0.05em;">
                @lang('UPI Payment')
            </span>
        </div>

        <h5 class="card-title mb-1">@lang('Pay via Jio UPI')</h5>
        <p class="text-muted small mb-4">@lang('Scan QR code or tap Pay Now button to complete payment')</p>

        {{-- ── Amount Summary ──────────────────────────────────────────────────── --}}
        <ul class="list-group list-group-flush mb-4 text-start">
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-muted">@lang('Amount to Pay')</span>
                <strong class="text--success">
                    {{ showAmount($deposit->final_amount, currencyFormat: false) }}
                    {{ __($deposit->method_currency) }}
                </strong>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-muted">@lang('You will get')</span>
                <strong>{{ showAmount($deposit->amount) }} {{ __(gs('cur_text')) }}</strong>
            </li>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span class="text-muted">@lang('Pay to')</span>
                <strong>{{ $data->payee_vpa }}</strong>
            </li>
        </ul>

        {{-- ── QR Code ─────────────────────────────────────────────────────────── --}}
        <div class="mb-3">
            <p class="small text-muted mb-2">@lang('Scan with any UPI app')</p>
            <img id="jio-qr"
                 src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&data={{ urlencode($data->intent_url) }}"
                 alt="@lang('UPI QR Code')"
                 class="img-fluid border rounded p-2"
                 style="max-width: 220px; background: #fff;">
        </div>

        {{-- ── UPI Deep Link (mobile only) ─────────────────────────────────────── --}}
        <a href="{{ $data->intent_url }}"
           class="btn btn--base w-100 mb-2"
           id="pay-btn">
            @lang('Open UPI App') &nbsp;&#8377;{{ number_format($data->amount, 2) }}
        </a>

        {{-- ── Countdown Timer ─────────────────────────────────────────────────── --}}
        <div class="alert alert-warning py-2 mt-3" id="timer-box">
            @lang('QR expires in') &nbsp;<strong id="countdown">{{ $data->expiry_min }}:00</strong>
        </div>

        {{-- ── Status Message ──────────────────────────────────────────────────── --}}
        <div id="status-box" class="mt-3">
            <div class="d-flex align-items-center justify-content-center gap-2 text-muted small">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <span id="status-text">@lang('Waiting for payment confirmation...')</span>
            </div>
        </div>

        <div id="error-box" class="alert alert-danger mt-3 d-none"></div>

    </div>
</div>
@endsection

@push('script')
<script>
(function ($) {
    "use strict";

    const trx        = @json($data->trx);
    const statusUrl  = @json($data->status_url);
    const expiryMins = parseInt(@json($data->expiry_min));
    const csrfToken  = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    // ── Countdown Timer ────────────────────────────────────────────────────
    let totalSeconds = expiryMins * 60;
    const countEl    = document.getElementById('countdown');
    let poller;

    const timer = setInterval(function () {
        totalSeconds--;
        const m = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
        const s = String(totalSeconds % 60).padStart(2, '0');
        countEl.textContent = m + ':' + s;

        if (totalSeconds <= 0) {
            clearInterval(timer);
            if (poller) clearInterval(poller);

            $('#timer-box')
                .removeClass('alert-warning')
                .addClass('alert-danger')
                .html('@lang("QR expired. Please go back and try again.")');

            $('#pay-btn').prop('disabled', true).text('@lang("Expired")');
            $('#status-box').hide();
        }
    }, 1000);

    // ── Status Polling (every 5 seconds) ──────────────────────────────────
    poller = setInterval(function () {
        $.ajax({
            url: statusUrl,
            method: 'GET',
            data: { trx: trx, _token: csrfToken },
            success: function (res) {
                if (res.status === 'success') {
                    clearInterval(timer);
                    clearInterval(poller);

                    $('#status-box').html(
                        '<div class="alert alert-success py-2">' +
                        '<strong>@lang("Payment Successful!")</strong> @lang("Redirecting...")' +
                        '</div>'
                    );

                    setTimeout(function () {
                        window.location.href = res.redirect;
                    }, 1500);

                } else if (res.status === 'failed') {
                    clearInterval(timer);
                    clearInterval(poller);

                    $('#status-box').hide();
                    $('#error-box')
                        .removeClass('d-none')
                        .text(res.message || '@lang("Payment failed. Please try again.")');
                }
                // 'pending' — keep polling silently
            },
            error: function () {
                // Network blip — keep polling silently
            }
        });
    }, 5000);

})(jQuery);
</script>
@endpush