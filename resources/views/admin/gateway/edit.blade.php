@extends('layouts.app-master')

@section('content')
@php $s = fn ($k) => old($k, $gateway->setting($k)); $chk = fn ($k) => old('_token') ? old($k) : $gateway->setting($k); @endphp
<div class="container py-4" style="max-width: 860px;">
    @include('layouts.partials.messages')
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif

    <h2 class="fw-bold mb-1"><i class="bi bi-credit-card-2-front text-primary me-2"></i>{{ __('Payment gateway') }}</h2>
    <p class="text-muted">{{ __('Mercado Pago ·') }} {{ $gateway->isReady() ? __('active') : __('inactive') }}
        @if($gateway->isConfigured())<span class="badge {{ $gateway->isSandbox() ? 'bg-warning text-dark' : 'bg-success' }}">{{ $gateway->isSandbox() ? __('test mode') : __('production') }}</span>@endif
    </p>

    <form method="POST" action="{{ route('admin.gateway.update') }}" class="card border-0 shadow-sm mb-4" style="border-radius: 12px;" autocomplete="off">
        @csrf
        <div class="card-body p-4">
            <div class="form-check form-switch mb-4">
                <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" @checked(old('_token') ? old('enabled') : $gateway->enabled)>
                <label class="form-check-label fw-bold" for="enabled">{{ __('Accept online payments') }}</label>
            </div>

            <h6 class="fw-bold">{{ __('Credentials') }}</h6>
            <div class="mb-3">
                <label class="form-label small" for="access_token">{{ __('Access token') }}</label>
                <input type="password" class="form-control" id="access_token" name="access_token" placeholder="{{ $maskedToken ?: 'APP_USR-… / TEST-…' }}" autocomplete="new-password">
                <div class="form-text">{{ __('Stored encrypted. Leave blank to keep the current one.') }}</div>
            </div>
            <div class="mb-3">
                <label class="form-label small" for="public_key">{{ __('Public key') }}</label>
                <input type="text" class="form-control" id="public_key" name="public_key" value="{{ old('public_key', $gateway->public_key) }}" placeholder="APP_USR-… / TEST-…">
                <div class="form-text">{{ __('Used in the browser to turn the card data into a token. Required for card payments.') }}</div>
            </div>
            <div class="mb-4">
                <label class="form-label small" for="webhook_secret">{{ __('Webhook secret (signature)') }}</label>
                <input type="password" class="form-control" id="webhook_secret" name="webhook_secret" placeholder="{{ $maskedSecret }}" autocomplete="new-password">
                <div class="form-check mt-1">
                    <input class="form-check-input" type="checkbox" id="clear_webhook_secret" name="clear_webhook_secret" value="1">
                    <label class="form-check-label small" for="clear_webhook_secret">{{ __('Remove the stored secret') }}</label>
                </div>
            </div>

            <h6 class="fw-bold">{{ __('Payment methods') }}</h6>
            <div class="form-check mb-1">
                <input class="form-check-input" type="checkbox" id="card_enabled" name="card_enabled" value="1" @checked($chk('card_enabled'))>
                <label class="form-check-label" for="card_enabled"><i class="bi bi-credit-card me-1"></i>{{ __('Credit card') }}</label>
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
                <div class="col-md-6">
                    <label class="form-label small" for="statement_descriptor">{{ __('Name on the card statement') }}</label>
                    <input type="text" maxlength="22" class="form-control" id="statement_descriptor" name="statement_descriptor" value="{{ $s('statement_descriptor') }}">
                </div>
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
            <div class="form-text mb-4">{{ __('Card: the card is charged by Mercado Pago every cycle. Pix has no automatic debit: each renewal invoice gets a Pix code that is generated and emailed to the customer.') }}</div>

            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>{{ __('Save') }}</button>
        </div>
    </form>

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="card-body p-4">
            <h6 class="fw-bold">{{ __('Webhook') }}</h6>
            <p class="small text-muted mb-2">{{ __('In Mercado Pago > Your integrations > Webhooks, register this URL (production and test) and select the topics below.') }}</p>
            <div class="input-group mb-2">
                <input type="text" readonly class="form-control form-control-sm" value="{{ $webhookUrl }}" id="webhookUrl">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('webhookUrl').value)"><i class="bi bi-clipboard"></i></button>
            </div>
            <ul class="small mb-3">
                <li><code>payment</code> {{ __('— Pix /') }} {{ __('card') }}</li>
                <li><code>subscription_authorized_payment</code> — {{ __('recurring card charges') }}</li>
                <li><code>subscription_preapproval</code> — {{ __('recurring card authorization') }}</li>
            </ul>
            @unless(str_starts_with($webhookUrl, 'https://'))
                <div class="alert alert-warning small">{{ __('This URL is not public https; Mercado Pago will not accept it as notification URL until APP_URL is https.') }}</div>
            @endunless
            <form method="POST" action="{{ route('admin.gateway.test') }}">
                @csrf
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
