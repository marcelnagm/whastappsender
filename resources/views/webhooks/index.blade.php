@extends('layouts.app-master')

@section('template_title', __('Outbound webhooks'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-plug me-1"></i> {{ __('Outbound webhooks') }}</h4>
  <p class="text-muted small">{{ __('Send events to Zapier, n8n, Make or your own system. Each call is a JSON POST signed in the X-Sama-Signature header (HMAC-SHA256 of the body with the secret). To read or change data use the REST API with an API key.') }}</p>
  @include('layouts.partials.messages')

  <form method="POST" action="{{ route('webhooks-out.store') }}" class="card p-3 mb-3">
    @csrf
    <div class="row g-2 mb-2">
      <div class="col-md-4"><input name="name" class="form-control" maxlength="80" required placeholder="{{ __('Name') }}" value="{{ old('name') }}"></div>
      <div class="col-md-8"><input name="url" type="url" class="form-control" maxlength="500" required placeholder="https://hooks.zapier.com/..." value="{{ old('url') }}">@error('url')<div class="text-danger small">{{ $message }}</div>@enderror</div>
    </div>
    <div class="small mb-2">@foreach ($events as $ev)<label class="me-3 d-inline-block"><input type="checkbox" name="events[]" value="{{ $ev }}" @checked(in_array($ev, old('events', [])))> <code>{{ $ev }}</code></label>@endforeach</div>
    <div><button class="btn btn-primary btn-sm">{{ __('Add endpoint') }}</button></div>
  </form>

  @foreach ($hooks as $h)
    <div class="card p-3 mb-2">
      <div class="d-flex align-items-center gap-2">
        <strong>{{ $h->name }}</strong>
        @if ($h->active)<span class="badge bg-success">{{ __('Active') }}</span>@else<span class="badge bg-secondary">{{ __('Paused') }}</span>@endif
        @if ($h->failures)<span class="badge bg-warning text-dark">{{ $h->failures }} {{ __('failures') }}</span>@endif
        <span class="ms-auto d-flex gap-1">
          <form method="POST" action="{{ route('webhooks-out.test', $h->id) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ __('Send test') }}</button></form>
          <form method="POST" action="{{ route('webhooks-out.toggle', $h->id) }}">@csrf<button class="btn btn-sm btn-outline-secondary">{{ $h->active ? __('Pause') : __('Resume') }}</button></form>
          <form method="POST" action="{{ route('webhooks-out.destroy', $h->id) }}" onsubmit="return confirm('{{ __('Remove this endpoint?') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button></form>
        </span>
      </div>
      <div class="small text-muted text-break">{{ $h->url }}</div>
      <div class="small">{{ __('Events') }}: @foreach ((array) $h->events as $ev)<code>{{ $ev }}</code> @endforeach</div>
      <div class="small">{{ __('Secret') }}: <code>{{ $h->secret }}</code></div>
    </div>
  @endforeach

  <h6 class="mt-3">{{ __('Latest deliveries') }}</h6>
  <table class="table table-sm small"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Event') }}</th><th>{{ __('Result') }}</th></tr></thead><tbody>
    @forelse ($deliveries as $d)
      <tr><td>{{ $d->created_at->format('d/m H:i:s') }}</td><td><code>{{ $d->event }}</code></td><td>@if ($d->ok)<span class="text-success">{{ $d->status_code }}</span>@else<span class="text-danger">{{ $d->error ?: __('Waiting') }}</span> ({{ $d->attempts }})@endif</td></tr>
    @empty
      <tr><td colspan="3" class="text-muted">{{ __('No deliveries yet.') }}</td></tr>
    @endforelse
  </tbody></table>
</div>
@endsection
