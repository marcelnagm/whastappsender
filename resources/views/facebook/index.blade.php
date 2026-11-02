@extends('layouts.app-master')

@section('template_title', __('Facebook'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-9">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-facebook me-2" style="color:#1877f2"></i>{{ __('Facebook') }}</h1>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif
    @if (!$configured)
      <div class="alert alert-warning border-0">{{ __('Facebook is not configured on this server (FACEBOOK_APP_SECRET and FACEBOOK_VERIFY_TOKEN).') }}</div>
    @endif

    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">{{ __('Connect an account') }}</h2>
      <ol class="small text-muted">
        <li>{{ __('Use a Facebook Page (you must be admin of it) added to your Meta app with the Messenger product.') }}</li>
        <li>{{ __('In the Meta app dashboard (Messenger > Webhooks) set this callback URL, the verify token from your .env, and subscribe the Page to messages:') }} <code>{{ $callback }}</code></li>
        <li>{{ __('Generate the Page access token (Messenger > Access tokens) and paste it below.') }}</li>
      </ol>
      <form method="POST" action="{{ route('facebook.store') }}" class="row g-2">
        @csrf
        <div class="col-md-9">
          <input type="password" name="token" autocomplete="off" class="form-control @error('token') is-invalid @enderror" placeholder="EAA..." required>
          @error('token')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary">{{ __('Connect') }}</button></div>
      </form>
      <div class="small text-muted mt-3">{{ __('Facebook only accepts replies up to :hours hours after the customer\'s last message, and the Page cannot start conversations.', ['hours' => $windowHours]) }}</div>
    </div></div>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Account') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
          <tbody>
          @forelse ($accounts as $acc)
            <tr>
              <td class="fw-semibold">{{ $acc->name }}<div class="small text-muted">ID {{ $acc->facebook_page_id }}</div></td>
              <td><span class="badge bg-success">{{ __('Connected') }}</span></td>
              <td class="text-end">
                <form method="POST" action="{{ route('facebook.refresh', $acc) }}" class="d-inline">@csrf
                  <button class="btn btn-sm btn-outline-secondary">{{ __('Renew connection') }}</button>
                </form>
                <form method="POST" action="{{ route('facebook.destroy', $acc) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove this account?') }}')">@csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="3" class="text-center text-muted py-4">{{ __('No Facebook Page connected yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>
@endsection
