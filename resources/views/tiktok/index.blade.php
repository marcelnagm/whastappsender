@extends('layouts.app-master')

@section('template_title', __('TikTok'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-9">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-tiktok me-2"></i>{{ __('TikTok') }}</h1>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif
    @if (!$configured)
      <div class="alert alert-warning border-0">{{ __('TikTok is not configured on this server (TIKTOK_APP_ID and TIKTOK_APP_SECRET).') }}</div>
    @endif

    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">{{ __('Connect an account') }}</h2>
      <ol class="small text-muted">
        <li>{{ __('Use a TikTok Business Account in an eligible region that accepts messages from everyone.') }}</li>
        <li>{{ __('In the TikTok app settings use this redirect URL and webhook URL:') }}<br><code>{{ $redirectUri }}</code><br><code>{{ $webhookUrl }}</code></li>
        <li>{{ __('Click the button and authorize the account on TikTok.') }}</li>
      </ol>
      <a href="{{ route('tiktok.connect') }}" class="btn btn-dark @if(!$configured) disabled @endif"><i class="bi bi-tiktok me-1"></i>{{ __('Connect with TikTok') }}</a>
      <div class="small text-muted mt-3">{{ __('TikTok only accepts replies up to :hours hours after the customer\'s last message, and the account cannot start conversations. Messages are text or one image. Bulk campaigns stay on WhatsApp.', ['hours' => $windowHours]) }}</div>
    </div></div>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Account') }}</th><th>{{ __('Authorization') }}</th><th></th></tr></thead>
          <tbody>
          @forelse ($accounts as $acc)
            <tr>
              <td class="fw-semibold">{{ $acc->name }}</td>
              <td>
                @if ($acc->tiktok_refresh_expires_at && $acc->tiktok_refresh_expires_at->isPast())
                  <span class="badge bg-danger">{{ __('Expired: connect again') }}</span>
                @else
                  <span class="badge bg-success">{{ __('Connected') }}</span>
                @endif
              </td>
              <td class="text-end">
                <form method="POST" action="{{ route('tiktok.refresh', $acc) }}" class="d-inline">@csrf
                  <button class="btn btn-sm btn-outline-secondary">{{ __('Renew connection') }}</button>
                </form>
                <form method="POST" action="{{ route('tiktok.destroy', $acc) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove this account?') }}')">@csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="3" class="text-center text-muted py-4">{{ __('No TikTok account connected yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>
@endsection
