@extends('layouts.app-master')

@section('template_title', __('Consent'))

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-shield-check me-2"></i>{{ __('Consent') }}</h1>
    <a href="{{ route('consent.export') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>{{ __('Export proof (CSV)') }}</a>
  </div>

  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif

  <div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('With consent') }}</div><div class="h3 mb-0 text-success">{{ $granted }}</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('No record') }}</div><div class="h3 mb-0">{{ $unknown }}</div></div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('Opted out') }}</div><div class="h3 mb-0 text-danger">{{ $revoked }}</div></div></div></div>
  </div>

  <form method="POST" action="{{ route('consent.settings') }}" class="card card-body border-0 shadow-sm mb-3">
    @csrf
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch" id="req" name="consent_required" value="1" @checked($required)>
      <label class="form-check-label fw-semibold" for="req">{{ __('Campaigns only reach contacts with recorded consent') }}</label>
    </div>
    <div class="form-text mb-2">{{ __('With this on, contacts without a consent record (and those who opted out) are left out of every campaign and welcome message. Consent is recorded by contact forms with a consent box, by the opt-in words (:words), when you register it by hand on a contact, or on import.', ['words' => implode(', ', config('consent.optin_keywords'))]) }}</div>
    <div><button class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>
  </form>

  <form method="POST" action="{{ route('consent.optout') }}" class="card card-body border-0 shadow-sm mb-3" x-data>
    @csrf
    <div class="fw-semibold mb-1"><i class="bi bi-box-arrow-right me-1"></i>{{ __('Let customers stop by themselves') }}</div>
    <div class="form-text mb-2">{{ __('Customers can always opt out by sending one of these words (:words) on any channel. Choose what every campaign message tells them:', ['words' => implode(', ', config('consent.optout_keywords'))]) }}</div>
    @foreach (['off' => __('Nothing (they can still use the words)'), 'keyword' => __('Reply with the word (:word)', ['word' => $optoutWord]), 'link' => __('Link to a page where they confirm'), 'both' => __('Both: the word and the link')] as $m => $label)
      <div class="form-check">
        <input class="form-check-input" type="radio" name="optout_mode" id="oo-{{ $m }}" value="{{ $m }}" @checked($optoutMode === $m)>
        <label class="form-check-label" for="oo-{{ $m }}">{{ $label }}</label>
      </div>
    @endforeach
    <label class="form-label small mt-3 mb-1" for="oo-text">{{ __('Your own sentence (optional)') }}</label>
    <textarea class="form-control form-control-sm" id="oo-text" name="optout_text" rows="2" maxlength="400" placeholder="{{ __('Use {word} for the word and {link} for the personal link') }}">{{ $optoutText }}</textarea>
    <div class="form-text mb-2">{{ __('Default') }}: {{ $optoutDefaults['both'] }}</div>
    <hr>
    <div class="form-check form-switch">
      <input class="form-check-input" type="checkbox" role="switch" id="oo-confirm" name="optout_confirm" value="1" @checked($optoutConfirm)>
      <label class="form-check-label fw-semibold" for="oo-confirm">{{ __('Confirm in the conversation when a customer opts out by writing') }}</label>
    </div>
    <div class="form-text mb-2">{{ __('Sends one short answer ("Done, you will not receive more messages...") on the same channel, so the customer knows it worked and how to come back. Opting back in is confirmed too.') }}</div>
    <textarea class="form-control form-control-sm" name="optout_confirm_text" rows="2" maxlength="400" placeholder="{{ __('Your own confirmation (optional). Use {company}, {optin} and {link}') }}">{{ $optoutConfirmText }}</textarea>
    <div class="form-text mb-2">{{ __('The link is personal for each contact and opens a page where the person confirms (opening it alone changes nothing). It applies to WhatsApp campaigns and to campaigns sent on other channels.') }}</div>
    <div><button class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>
  </form>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">{{ __('Latest records') }}</div>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="small text-muted"><tr><th class="ps-3">{{ __('Date') }}</th><th>{{ __('Contact') }}</th><th>{{ __('Action') }}</th><th>{{ __('Source') }}</th><th>{{ __('Evidence') }}</th></tr></thead>
        <tbody>
          @forelse ($logs as $l)
            <tr>
              <td class="ps-3 small text-nowrap">{{ \Illuminate\Support\Carbon::parse($l->created_at)->format('d/m/Y H:i') }}</td>
              <td class="small">{{ $l->name }}<div class="text-muted">{{ $l->contact }}</div></td>
              <td><span class="badge {{ $l->action === 'granted' ? 'bg-success' : 'bg-danger' }}">{{ $l->action === 'granted' ? __('Granted') : __('Revoked') }}</span></td>
              <td class="small">{{ $l->source }}</td>
              <td class="small text-break">{{ $l->evidence }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('Nothing here.') }}</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
