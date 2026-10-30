@extends('layouts.app-master')

@section('content')
@php
    $s = fn ($k) => old($k, $gateway->setting($k)); $chk = fn ($k) => old('_token') ? old($k) : $gateway->setting($k);
    $isMp = $provider === 'mercadopago'; $isEfi = $provider === 'efi'; $isStripe = $provider === 'stripe'; $isKeyEnv = in_array($provider, ['stripe', 'pagarme']); $hasSecretQuery = in_array($provider, ['efi', 'abacatepay', 'pagarme', 'pagbank']); $label = $info['label'];
@endphp
<div class="container py-4" style="max-width: 860px;">
    @include('layouts.partials.messages')
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif

    <h2 class="fw-bold mb-3"><i class="bi bi-credit-card-2-front text-primary me-2"></i>{{ __('Payment gateway') }}</h2>
    <p class="small text-muted">{{ __('Enable one or more providers: when more than one is active the customer chooses at checkout.') }}</p>
    <ul class="nav nav-pills mb-3">
        @foreach($tabs as $key => $tab)
            <li class="nav-item"><a class="nav-link {{ $key === $provider ? 'active' : '' }}" href="{{ route('admin.gateway.edit', $key === 'mercadopago' ? [] : ['provider' => $key]) }}">
                {{ $tab['label'] }} @if($tab['ready'])<i class="bi bi-check-circle-fill text-success ms-1" title="{{ __('active') }}"></i>@endif</a></li>
        @endforeach
    </ul>
    <div class="alert alert-light border small py-2">
        <strong>{{ __('Public fees (orientation, confirm with :name):', ['name' => $label]) }}</strong>
        Pix {{ $info['fees']['pix'] }} · {{ __('card') }} {{ $info['fees']['card'] }} · {{ __('settlement') }}: {{ $info['fees']['settlement'] }}
        · <a href="{{ $info['docs'] }}" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
    </div>
    <p class="text-muted">{{ $label }} · {{ $gateway->isReady() ? __('active') : __('inactive') }}
        @if($gateway->isConfigured())<span class="badge {{ $gateway->isSandbox() ? 'bg-warning text-dark' : 'bg-success' }}">{{ $gateway->isSandbox() ? __('test mode') : __('production') }}</span>@endif
    </p>

    <form method="POST" action="{{ route('admin.gateway.update') }}" class="card border-0 shadow-sm mb-4" style="border-radius: 12px;" autocomplete="off">
        @csrf
        <input type="hidden" name="provider" value="{{ $provider }}">
        <div class="card-body p-4">
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" @checked(old('_token') ? old('enabled') : $gateway->enabled)>
                <label class="form-check-label fw-bold" for="enabled">{{ __('Accept online payments') }}</label>
            </div>

            <h6 class="fw-bold">{{ __('Credentials') }}</h6>
            <div class="mb-3">
                <label class="form-label small" for="access_token">
                    @if($isEfi){{ __('Client secret') }}@elseif($isMp){{ __('Access token') }}@elseif($isStripe || $provider === 'pagarme'){{ __('Secret key') }}@elseif($provider === 'pagbank'){{ __('Token') }}@else{{ __('API key') }}@endif
                </label>
                <input type="password" class="form-control" id="access_token" name="access_token" placeholder="{{ $maskedToken ?: ($isMp ? 'APP_USR-… / TEST-…' : '') }}" autocomplete="new-password">
                <div class="form-text">{{ __('Stored encrypted. Leave blank to keep the current one.') }} <a href="{{ $info['keys'] }}" target="_blank" rel="noopener">{{ __('Where do I find it?') }}</a></div>
            </div>
            @if($isMp || $isEfi)
            <div class="mb-3">
                <label class="form-label small" for="public_key">{{ $isEfi ? __('Client ID') : __('Public key') }}</label>
                <input type="text" class="form-control" id="public_key" name="public_key" value="{{ old('public_key', $gateway->public_key) }}" placeholder="{{ $isMp ? 'APP_USR-… / TEST-…' : 'Client_Id_…' }}">
                @if($isMp)<div class="form-text">{{ __('Used in the browser to turn the card data into a token. Required for card payments.') }}</div>@endif
            </div>
            @endif
            @unless($isMp || $isKeyEnv)
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="sandbox" name="sandbox" value="1" @checked($chk('sandbox'))>
                <label class="form-check-label" for="sandbox">{{ __('Test mode (sandbox)') }}@if($provider === 'abacatepay') — {{ __('AbacatePay uses the key type: a dev-mode key already creates test charges') }}@endif</label>
            </div>
            @endunless
            @if($isEfi)
            <div class="row g-3 mb-3">
                <div class="col-md-7">
                    <label class="form-label small" for="certificate_path">{{ __('Certificate (.pem) path on the server') }}</label>
                    <input type="text" class="form-control" id="certificate_path" name="certificate_path" value="{{ $s('certificate_path') }}" placeholder="/var/www/secure/efi-producao.pem">
                    <div class="form-text">{{ __('Required for Pix. Convert the .p12 from Efí: openssl pkcs12 -in cert.p12 -out efi.pem -nodes. Keep the file outside the public folder.') }}</div>
                </div>
                <div class="col-md-5">
                    <label class="form-label small" for="pix_key">{{ __('Pix key that receives the money') }}</label>
                    <input type="text" class="form-control" id="pix_key" name="pix_key" value="{{ $s('pix_key') }}">
                </div>
            </div>
            @endif
            <div class="mb-4">
                <label class="form-label small" for="webhook_secret">{{ $isMp ? __('Webhook secret (signature)') : ($isStripe ? __('Signing secret (whsec_…)') : __('Webhook secret')) }}</label>
                <div class="input-group">
                    <input type="password" class="form-control" id="webhook_secret" name="webhook_secret" placeholder="{{ $maskedSecret }}" autocomplete="new-password">
                    @if($hasSecretQuery || $provider === 'asaas')<button type="button" class="btn btn-outline-secondary" onclick="var i=document.getElementById('webhook_secret');i.value=Array.from(crypto.getRandomValues(new Uint8Array(24))).map(function(b){return b.toString(16).padStart(2,'0')}).join('');i.type='text';">{{ __('Generate') }}</button>@endif
                </div>
                @if($isStripe)<div class="form-text">{{ __('Stripe signs every notification: paste the signing secret of the webhook endpoint (Stripe → Developers → Webhooks). Without it all notifications are rejected.') }}</div>@elseif(!$isMp)<div class="form-text">{{ $provider === 'asaas' ? __('Asaas: paste this same value as the "Auth token" of the webhook in the Asaas panel (32+ characters).') : __('Sent back to us in the webhook URL (?secret=…) so only the provider can notify. Use Generate, then save.') }}</div>@endif
                <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" id="clear_webhook_secret" name="clear_webhook_secret" value="1">
                    <label class="form-check-label small" for="clear_webhook_secret">{{ __('Remove the stored secret') }}</label>
                </div>
            </div>

            <h6 class="fw-bold">{{ __('Payment methods') }}</h6>
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="card_enabled" name="card_enabled" value="1" @checked($chk('card_enabled'))>
                <label class="form-check-label" for="card_enabled"><i class="bi bi-credit-card me-1"></i>{{ __('Credit card') }}@unless($isMp) — {{ __('paid on the provider\'s secure page') }}@endunless</label>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="pix_enabled" name="pix_enabled" value="1" @checked($chk('pix_enabled'))>
                <label class="form-check-label" for="pix_enabled"><i class="bi bi-qr-code me-1"></i>Pix</label>
            </div>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label small" for="pix_expiration_minutes">{{ __('Pix validity (minutes)') }}</label>
                    <input type="number" min="30" max="43200" class="form-control" id="pix_expiration_minutes" name="pix_expiration_minutes" value="{{ $s('pix_expiration_minutes') }}">
                </div>
                @if($isMp)
                <div class="col-md-6">
                    <label class="form-label small" for="statement_descriptor">{{ __('Name on the card statement') }}</label>
                    <input type="text" maxlength="22" class="form-control" id="statement_descriptor" name="statement_descriptor" value="{{ $s('statement_descriptor') }}">
                </div>
                @endif
            </div>

            <h6 class="fw-bold">{{ __('Recurrence') }}</h6>
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="recurrence_enabled" name="recurrence_enabled" value="1" @checked($chk('recurrence_enabled'))>
                <label class="form-check-label" for="recurrence_enabled">{{ __('Let customers choose automatic renewal (card and Pix)') }}</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="recurrence_default" name="recurrence_default" value="1" @checked($chk('recurrence_default'))>
                <label class="form-check-label" for="recurrence_default">{{ __('Automatic renewal starts checked') }}</label>
            </div>
            <div class="form-text mb-4">
                @if($isMp){{ __('Card: the card is charged by Mercado Pago every cycle. Pix has no automatic debit: each renewal invoice gets a Pix code that is generated and emailed to the customer.') }}
                @else{{ __('With this provider the card is paid once per invoice. Automatic renewal works with Pix: each renewal invoice gets a new Pix code that is generated and emailed to the customer.') }}@endif
            </div>

            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>{{ __('Save') }}</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-4">
            <h6 class="fw-bold">{{ __('Webhook') }}</h6>
            @if($isMp)
            <p class="small text-muted mb-2">{{ __('In Mercado Pago > Your integrations > Webhooks, register this URL (production and test) and select the topics below.') }}</p>
            @elseif($provider === 'asaas')
            <p class="small text-muted mb-2">{{ __('In Asaas > Integrations > Webhooks, create a webhook with this URL, the same auth token as above, and the events PAYMENT_CONFIRMED and PAYMENT_RECEIVED.') }}</p>
            @elseif($isEfi)
            <p class="small text-muted mb-2">{{ __('Pix: use the button below to register the URL at Efí. Card: the notification URL is sent with each charge automatically.') }}</p>
            @elseif($isStripe)
            <p class="small text-muted mb-2">{{ __('In Stripe → Developers → Webhooks add an endpoint with this URL and the events checkout.session.completed and checkout.session.async_payment_succeeded, then paste its signing secret above.') }}</p>
            @elseif($provider === 'pagarme')
            <p class="small text-muted mb-2">{{ __('In Pagar.me → Settings → Webhooks add this URL with ?secret= followed by the webhook secret above, and the events order.paid and charge.paid.') }}</p>
            @elseif($provider === 'pagbank')
            <p class="small text-muted mb-2">{{ __('PagBank: the notification URL (with ?secret=) is sent with every order and checkout automatically; just generate and save the webhook secret above.') }}</p>
            @else
            <p class="small text-muted mb-2">{{ __('In the AbacatePay dashboard, register this URL as the webhook, adding ?secret= followed by the webhook secret above.') }}</p>
            @endif
            <div class="input-group mb-2">
                <input type="text" readonly class="form-control form-control-sm" value="{{ $webhookUrl }}" id="webhookUrl">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)"><i class="bi bi-clipboard"></i></button>
            </div>
            @if($isMp)
            <ul class="small mb-3">
                <li><code>payment</code> {{ __('— Pix /') }} {{ __('card') }}</li>
                <li><code>subscription_authorized_payment</code> — {{ __('recurring card charges') }}</li>
                <li><code>subscription_preapproval</code> — {{ __('recurring card authorization') }}</li>
            </ul>
            @endif
            @unless(str_starts_with($webhookUrl, 'https://'))
                <div class="alert alert-warning small">{{ __('This URL is not public https; the provider will not accept it as notification URL until APP_URL is https.') }}</div>
            @endunless
            @if($isEfi)
            <form method="POST" action="{{ route('admin.gateway.webhook') }}" class="d-inline">
                @csrf
                <button class="btn btn-sm btn-outline-secondary me-1"><i class="bi bi-link-45deg me-1"></i>{{ __('Register Pix webhook') }}</button>
            </form>
            @endif
            <form method="POST" action="{{ route('admin.gateway.test') }}" class="d-inline">
                @csrf
                <input type="hidden" name="provider" value="{{ $provider }}">
                <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plug me-1"></i>{{ __('Test connection') }}</button>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body p-4">
            <h6 class="fw-bold">{{ __('Latest notifications') }}</h6>
            <div class="table-responsive">
                <table class="table table-sm small mb-0">
                    <thead><tr><th>{{ __('When') }}</th><th>{{ __('Type') }}</th><th>{{ __('Resource') }}</th><th>{{ __('Status') }}</th><th>{{ __('Note') }}</th></tr></thead>
                    <tbody>
                    @forelse($events as $e)
                        <tr><td>{{ $e->created_at->format('d/m H:i') }}</td><td>{{ $e->type }}</td><td>{{ $e->resource_id }}</td><td>{{ $e->status }}</td><td class="text-muted">{{ $e->note }}</td></tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">{{ __('Nothing received yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
