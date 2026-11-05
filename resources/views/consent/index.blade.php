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
