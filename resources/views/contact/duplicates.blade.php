@extends('layouts.app-master')

@section('template_title', __('Duplicates'))

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-intersect me-2"></i>{{ __('Duplicate contacts') }}</h1>
    <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('Contacts') }}</a>
  </div>
  <p class="text-muted small">{{ __('People registered more than once: same e-mail, or the same phone written differently (country code, extra 9). Choose the record that stays: conversations, deals, tasks and consent proof of the others move to it, and empty fields are filled. An opt-out always survives.') }}</p>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  @forelse ($groups as $g)
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
      <div class="small text-muted mb-2">{{ ['email' => __('Same e-mail'), 'phone' => __('Same phone'), 'both' => __('Same e-mail and phone')][$g['reason']] }}</div>
      <form method="POST" action="{{ route('contacts.duplicates.merge') }}" onsubmit="return confirm('{{ __('Merge these contacts? This cannot be undone.') }}')">@csrf
        @foreach ($g['contacts'] as $i => $c)
          <div class="d-flex align-items-center gap-3 border-bottom py-2">
            <label class="d-flex align-items-center gap-2 flex-grow-1 mb-0">
              <input type="radio" name="keep_id" value="{{ $c->id }}" class="form-check-input" @checked($i === 0) required>
              <span><strong>{{ $c->name ?: '—' }}</strong> <span class="text-muted">{{ $c->contact }}</span>@if ($c->email) <span class="text-muted">· {{ $c->email }}</span>@endif</span>
            </label>
            <span class="small text-muted text-nowrap">{{ $c->chats_n }} {{ __('chats') }} · {{ $c->deals_n }} {{ __('deals') }}@if ($c->consent_status === 'revoked') · <span class="text-danger">{{ __('Opted out') }}</span>@endif</span>
            <input type="hidden" name="drop_ids[]" value="{{ $c->id }}">
          </div>
        @endforeach
        <div class="pt-2 d-flex gap-2">
          <button class="btn btn-primary btn-sm">{{ __('Merge into the selected one') }}</button>
        </div>
      </form>
      <form method="POST" action="{{ route('contacts.duplicates.ignore') }}" class="mt-2">@csrf
        @foreach ($g['keys'] as $k)<input type="hidden" name="keys[]" value="{{ $k }}">@endforeach
        <button class="btn btn-link btn-sm p-0">{{ __('They are different people') }}</button>
      </form>
    </div></div>
  @empty
    <div class="card border-0 shadow-sm"><div class="card-body text-muted">{{ __('No duplicates found.') }}</div></div>
  @endforelse
</div>
@endsection
