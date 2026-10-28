@extends('layouts.app-master')

@section('content')
@php $money = fn ($cents, $cur = 'BRL') => ($cur === 'BRL' ? 'R$ ' : $cur . ' ') . number_format($cents / 100, 2, ',', '.'); @endphp
<div class="container py-4" style="max-width: 760px;">
    <a href="{{ route('billing.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>{{ __('Back to plan and billing') }}</a>

    <div class="card border-0 shadow-sm mt-3" style="border-radius: 12px;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4 class="fw-bold mb-0">{{ __('Invoice') }} {{ $invoice->number }}</h4>
                    <div class="text-muted small">{{ $invoice->created_at->format('d/m/Y') }}</div>
                </div>
                @if($invoice->status === 'paid')<span class="badge bg-success fs-6">{{ __('Paid') }}</span>
                @elseif($invoice->status === 'void')<span class="badge bg-secondary fs-6">{{ __('Void') }}</span>
                @elseif($invoice->isOverdue())<span class="badge bg-danger fs-6">{{ __('Overdue') }}</span>
                @else<span class="badge bg-warning text-dark fs-6">{{ __('Open') }}</span>@endif
            </div>

            <table class="table small">
                <tr><td class="text-muted">{{ __('Description') }}</td><td class="text-end">{{ $invoice->description }}</td></tr>
                <tr><td class="text-muted">{{ __('Period') }}</td><td class="text-end">{{ optional($invoice->period_start)->format('d/m/Y') }} – {{ optional($invoice->period_end)->format('d/m/Y') }}</td></tr>
                <tr><td class="text-muted">{{ __('Due') }}</td><td class="text-end">{{ optional($invoice->due_at)->format('d/m/Y') }}</td></tr>
                @if($invoice->credit_cents > 0)
                    <tr><td class="text-muted">{{ __('Credit for unused time') }}</td><td class="text-end">− {{ $money($invoice->credit_cents, $invoice->currency) }}</td></tr>
                @endif
                @if($invoice->paid_at)
                    <tr><td class="text-muted">{{ __('Paid on') }}</td><td class="text-end">{{ $invoice->paid_at->format('d/m/Y H:i') }}</td></tr>
                @endif
                <tr class="fw-bold fs-5"><td>{{ __('Total') }}</td><td class="text-end">{{ $money($invoice->amount_cents, $invoice->currency) }}</td></tr>
            </table>

            @if($invoice->isOpen())
                @include('layouts.partials.messages')
                @if($errors->has('payment') || $lastError)
                    <div class="alert alert-danger small">{{ $errors->first('payment') ?: $lastError }}</div>
                @endif

                @if(!$gateway)
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        {{ __('Online payment is not available right now. Contact support to pay this invoice; access is restored as soon as the payment is confirmed.') }}
                    </div>
                @else
                    @if($pix)
                        <div class="border rounded p-3 mb-4 text-center" id="pixBox">
                            <h6 class="fw-bold"><i class="bi bi-qr-code me-1"></i>{{ __('Pay with Pix') }}</h6>
                            @if(!empty($pix['qr_base64']))<img alt="{{ __('Pix QR code') }}" class="img-fluid mb-2" style="max-width: 220px;" src="data:image/png;base64,{{ $pix['qr_base64'] }}">@endif
                            @if(!empty($pix['qr_code']))
                                <div class="input-group input-group-sm mb-2">
                                    <input type="text" readonly class="form-control" id="pixCode" value="{{ $pix['qr_code'] }}">
                                    <button type="button" class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('pixCode').value); this.innerText='{{ __('Copied') }}'">{{ __('Copy') }}</button>
                                </div>
                            @endif
                            @if(!empty($pix['expires_at']))<div class="small text-muted">{{ __('Valid until') }} {{ \Carbon\Carbon::parse($pix['expires_at'])->format('d/m/Y H:i') }}</div>@endif
                            <div class="small text-muted mt-2"><span class="spinner-border spinner-border-sm me-1"></span>{{ __('Waiting for the payment. This page updates by itself.') }}</div>
                        </div>
                    @endif

                    @if($cardUrl)
                        <div class="alert alert-info small d-flex justify-content-between align-items-center gap-2">
                            <span><i class="bi bi-credit-card me-1"></i>{{ __('A card payment page was opened for this invoice. If you did not finish, you can go back to it.') }}</span>
                            <a href="{{ $cardUrl }}" class="btn btn-sm btn-outline-primary text-nowrap">{{ __('Open card page') }}</a>
                        </div>
                    @endif

                    @if(count($available) > 1)
                        <div class="mb-3">
                            <div class="small fw-bold mb-1">{{ __('Pay with') }}</div>
                            <div class="d-flex flex-wrap gap-2">
                                @foreach($available as $key => $label)
                                    <a href="{{ route('billing.invoice', ['invoice' => $invoice, 'gateway' => $key]) }}" class="btn btn-sm {{ $key === $selected ? 'btn-primary' : 'btn-outline-primary' }}">{{ $label }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('billing.pay', $invoice) }}" id="payForm" novalidate>
                        @csrf
                        <input type="hidden" name="gateway" value="{{ $selected }}">
                        @if($errors->any() && !$errors->has('payment'))
                            <div class="alert alert-warning small"><ul class="mb-0 list-unstyled">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
                        @endif

                        <h6 class="fw-bold">{{ $pix ? __('Pay another way') : __('Pay this invoice') }}</h6>
                        <div class="btn-group mb-3 w-100" role="group">
                            @if(in_array('pix', $methods))
                                <input type="radio" class="btn-check" name="method" id="mPix" value="pix" autocomplete="off" @checked(old('method', $methods[0] ?? '') === 'pix')>
                                <label class="btn btn-outline-primary" for="mPix"><i class="bi bi-qr-code me-1"></i>Pix</label>
                            @endif
                            @if(in_array('card', $methods))
                                <input type="radio" class="btn-check" name="method" id="mCard" value="card" autocomplete="off" @checked(old('method', $methods[0] ?? '') === 'card')>
                                <label class="btn btn-outline-primary" for="mCard"><i class="bi bi-credit-card me-1"></i>{{ __('Credit card') }}</label>
                            @endif
                        </div>

                        <div class="mb-3">
                            <label class="form-label small" for="document">{{ __('CPF / CNPJ of the payer') }}</label>
                            <input type="text" class="form-control" id="document" name="document" inputmode="numeric" value="{{ old('document') }}" required>
                        </div>

                        @if(in_array('card', $methods) && $cardMode === 'redirect')
                            <div id="cardRedirect" class="border rounded p-3 mb-3 small text-muted" style="display:none;">
                                <i class="bi bi-lock me-1"></i>{{ __('You will be taken to the provider\'s secure page to enter the card. The card data never touches our servers.') }}
                            </div>
                        @endif

                        @if(in_array('card', $methods) && $cardMode === 'token')
                            <div id="cardFields" class="border rounded p-3 mb-3" style="display:none;">
                                <div class="row g-2">
                                    <div class="col-12"><input type="text" class="form-control" id="cardNumber" placeholder="{{ __('Card number') }}" inputmode="numeric" autocomplete="cc-number"></div>
                                    <div class="col-12"><input type="text" class="form-control" id="cardHolder" placeholder="{{ __('Name on the card') }}" autocomplete="cc-name"></div>
                                    <div class="col-6"><input type="text" class="form-control" id="cardExp" placeholder="{{ __('MM/AA') }}" inputmode="numeric" autocomplete="cc-exp"></div>
                                    <div class="col-6"><input type="text" class="form-control" id="cardCvv" placeholder="{{ __('CVV') }}" inputmode="numeric" autocomplete="cc-csc"></div>
                                </div>
                                <div class="small text-danger mt-2" id="cardError"></div>
                                <div class="form-text"><i class="bi bi-lock me-1"></i>{{ __('Card data goes straight to the payment provider and never touches our servers.') }}</div>
                                <input type="hidden" name="card_token" id="cardToken">
                                <input type="hidden" name="payment_method_id" id="cardBrand">
                                <input type="hidden" name="issuer_id" id="cardIssuer">
                            </div>
                        @endif

                        @if($recurrenceAllowed)
                            <div class="form-check mb-1" id="recurringBox">
                                <input type="hidden" name="recurring" value="0">
                                <input class="form-check-input" type="checkbox" id="recurring" name="recurring" value="1" @checked(old('_token') ? old('recurring') : ($recurrenceDefault || ($subscription && $subscription->auto_renew)))>
                                <label class="form-check-label" for="recurring">{{ __('Renew automatically every cycle') }}</label>
                            </div>
                            <div class="form-text mb-3" id="recurringHelp"></div>
                        @endif

                        <button class="btn btn-primary w-100" id="payBtn"><i class="bi bi-lock me-1"></i>{{ __('Pay') }} {{ $money($invoice->amount_cents, $invoice->currency) }}</button>
                    </form>

                    @if($subscription && $subscription->auto_renew)
                        <form method="POST" action="{{ route('billing.autorenew.stop') }}" class="mt-3" onsubmit="return confirm('{{ __('Turn off automatic renewal?') }}')">
                            @csrf
                            <button class="btn btn-link btn-sm text-muted p-0">{{ __('Automatic renewal is on (:method). Turn it off', ['method' => $subscription->payment_method === 'pix' ? 'Pix' : __('card')]) }}</button>
                        </form>
                    @endif
                @endif
            @elseif($invoice->status === 'paid' && $subscription && $subscription->auto_renew)
                <div class="alert alert-success small mb-0"><i class="bi bi-arrow-repeat me-1"></i>{{ __('Automatic renewal is on.') }}</div>
            @endif
        </div>
    </div>
</div>
@endsection

@if($invoice->isOpen() && $gateway)
@section('js')
@if(in_array('card', $methods) && $cardMode === 'token')<script src="https://sdk.mercadopago.com/js/v2"></script>@endif
<script>
(function () {
    var form = document.getElementById('payForm');
    var cardFields = document.getElementById('cardFields');
    var cardRedirect = document.getElementById('cardRedirect');
    var cardRecurrenceSupported = @json($cardRecurrenceSupported);
    var recurring = document.getElementById('recurring');
    var help = document.getElementById('recurringHelp');
    var recurringCardOk = @json($recurringCardOk);
    var mp = null;
    @if(in_array('card', $methods) && $cardMode === 'token' && $publicKey)
    try { mp = new MercadoPago(@json($publicKey), {locale: 'pt-BR'}); } catch (e) {}
    @endif

    function method() { var c = form.querySelector('input[name=method]:checked'); return c ? c.value : null; }

    function refresh() {
        var m = method();
        if (cardFields) cardFields.style.display = m === 'card' ? '' : 'none';
        if (cardRedirect) cardRedirect.style.display = m === 'card' ? '' : 'none';
        if (!recurring) return;
        var blocked = m === 'card' && !recurringCardOk;
        recurring.disabled = blocked;
        if (blocked) recurring.checked = false;
        help.textContent = blocked && !cardRecurrenceSupported ? @json(__('This provider does not charge the card automatically. Choose Pix to renew automatically, or pay this invoice by card now.'))
            : blocked ? @json(__('Automatic card renewal needs the full plan price; this invoice is prorated, so pay it once.'))
            : (m === 'pix' ? @json(__('Pix has no automatic debit: before each due date we generate a new Pix code and email it to you.'))
            : @json(__('The card is charged automatically every cycle. You can turn it off at any time.')));
    }
    form.querySelectorAll('input[name=method]').forEach(function (r) { r.addEventListener('change', refresh); });
    refresh();

    form.addEventListener('submit', async function (ev) {
        if (method() !== 'card' || !document.getElementById('cardToken')) return;
        if (document.getElementById('cardToken').value) return;
        ev.preventDefault();
        var btn = document.getElementById('payBtn'), err = document.getElementById('cardError');
        err.textContent = ''; btn.disabled = true;
        try {
            if (!mp) throw new Error(@json(__('Card payments are not available right now.')));
            var number = document.getElementById('cardNumber').value.replace(/\D/g, '');
            var exp = document.getElementById('cardExp').value.replace(/\D/g, '');
            var doc = document.getElementById('document').value.replace(/\D/g, '');
            var pm = await mp.getPaymentMethods({bin: number.substring(0, 8)});
            var info = pm.results && pm.results[0];
            var token = await mp.createCardToken({
                cardNumber: number,
                cardholderName: document.getElementById('cardHolder').value,
                cardExpirationMonth: exp.substring(0, 2),
                cardExpirationYear: exp.length === 4 ? '20' + exp.substring(2) : exp.substring(2),
                securityCode: document.getElementById('cardCvv').value,
                identificationType: doc.length === 14 ? 'CNPJ' : 'CPF',
                identificationNumber: doc
            });
            document.getElementById('cardToken').value = token.id;
            document.getElementById('cardBrand').value = info ? info.id : '';
            document.getElementById('cardIssuer').value = info && info.issuer ? info.issuer.id : '';
            ['cardNumber', 'cardCvv', 'cardExp', 'cardHolder'].forEach(function (id) { document.getElementById(id).value = ''; });
            form.submit();
        } catch (e) {
            err.textContent = (e && e.message) || @json(__('Check the card data and try again.'));
            if (e && e.length && e[0] && e[0].message) err.textContent = e[0].message;
            btn.disabled = false;
        }
    });

    @php $waiting = !empty($pix) || !empty($cardUrl) || (($invoice->gateway_payload['recurring'] ?? false) === true); @endphp
    @if($waiting)
    setInterval(function () {
        fetch(@json(route('billing.status', $invoice)), {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.paid || d.status !== 'open') location.reload(); })
            .catch(function () {});
    }, 5000);
    @endif
})();
</script>
@endsection
@endif
