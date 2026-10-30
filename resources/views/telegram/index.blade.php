@extends('layouts.app-master')

@section('template_title', __('Telegram'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-9">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-telegram text-primary me-2"></i>{{ __('Telegram') }}</h1>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif
    @if (!$httpsReady)
      <div class="alert alert-warning border-0">{{ __('Telegram only delivers messages to https addresses. Set APP_URL to your public https domain.') }}</div>
    @endif

    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">{{ __('Connect a bot') }}</h2>
      <ol class="small text-muted">
        <li>{{ __('Open @BotFather in Telegram and send /newbot.') }}</li>
        <li>{{ __('Copy the token it gives you and paste it below.') }}</li>
        <li>{{ __('Customers write to your bot; the AI agent and the Chat answer through the same bot.') }}</li>
      </ol>
      <form method="POST" action="{{ route('telegram.store') }}" class="row g-2">
        @csrf
        <div class="col-md-9">
          <input type="password" name="token" autocomplete="off" class="form-control @error('token') is-invalid @enderror" placeholder="123456789:AAH..." required>
          @error('token')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3 d-grid"><button class="btn btn-primary">{{ __('Connect') }}</button></div>
      </form>
      <div class="small text-muted mt-3">{{ __('Telegram bots cannot start conversations: the customer must message the bot first. Number-based campaigns stay on WhatsApp.') }}</div>
    </div></div>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Bot') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
          <tbody>
          @forelse ($bots as $bot)
            <tr>
              <td class="fw-semibold">{{ $bot->name }}<div class="small text-muted">{{ '@' . $bot->telegram_username }}</div></td>
              <td><span class="badge bg-success">{{ __('Connected') }}</span></td>
              <td class="text-end">
                <form method="POST" action="{{ route('telegram.reconnect', $bot) }}" class="d-inline">@csrf
                  <button class="btn btn-sm btn-outline-secondary">{{ __('Register webhook again') }}</button>
                </form>
                <form method="POST" action="{{ route('telegram.destroy', $bot) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove this bot?') }}')">@csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="3" class="text-center text-muted py-4">{{ __('No Telegram bot connected yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>
@endsection
