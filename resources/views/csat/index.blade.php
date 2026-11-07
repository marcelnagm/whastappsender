@extends('layouts.app-master')

@section('template_title', __('Satisfaction survey'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-emoji-smile me-1"></i> {{ __('Satisfaction survey') }}</h4>
  <p class="text-muted small">{{ __('When a conversation is closed, the customer is asked for a grade. The next message with just the number is the answer.') }}</p>
  @include('layouts.partials.messages')

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $stats['responses'] }}</div><div class="small text-muted">{{ __('Answers (30 days)') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $stats['sent'] ? round($stats['responses'] / $stats['sent'] * 100) . '%' : '—' }}</div><div class="small text-muted">{{ __('Response rate') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $stats['average'] ?? '—' }}</div><div class="small text-muted">{{ __('Average grade') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">@if ($cfg->scale === 'nps'){{ $stats['nps'] ?? '—' }}@else{{ isset($stats['satisfied']) ? $stats['satisfied'] . '%' : '—' }}@endif</div><div class="small text-muted">{{ $cfg->scale === 'nps' ? 'NPS' : __('Satisfied (4-5)') }}</div></div></div>
  </div>

  <form method="POST" action="{{ route('csat.update') }}" class="card p-3 mb-3">
    @csrf @method('PUT')
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="csatOn" @checked(old('enabled', $cfg->enabled))><label class="form-check-label fw-semibold" for="csatOn">{{ __('Ask for a grade when closing a conversation') }}</label></div>
    <div class="row g-2 mb-2">
      <div class="col-md-6"><label class="form-label small">{{ __('Scale') }}</label>
        <select name="scale" class="form-select"><option value="csat" @selected(old('scale', $cfg->scale) === 'csat')>{{ __('CSAT: 1 to 5') }}</option><option value="nps" @selected(old('scale', $cfg->scale) === 'nps')>{{ __('NPS: 0 to 10') }}</option></select></div>
      <div class="col-md-6"><label class="form-label small">{{ __('Valid for (hours)') }}</label><input type="number" name="expire_hours" min="1" max="168" class="form-control" value="{{ old('expire_hours', $cfg->expire_hours) }}"></div>
    </div>
    <label class="form-label small">{{ __('Question (empty = default)') }}</label>
    <textarea name="question" rows="2" maxlength="500" class="form-control mb-2" placeholder="{{ $cfg->questionText() }}">{{ old('question', $cfg->question) }}</textarea>
    <label class="form-label small">{{ __('Thank-you message (empty = default)') }}</label>
    <textarea name="thanks" rows="2" maxlength="500" class="form-control mb-3" placeholder="{{ $cfg->thanksText() }}">{{ old('thanks', $cfg->thanks) }}</textarea>
    <div><button class="btn btn-primary">{{ __('Save') }}</button></div>
  </form>

  <h6>{{ __('By attendant') }}</h6>
  <ul class="list-unstyled small mb-3">
    @forelse ($stats['by_agent'] as $uid => $s)<li>{{ optional($people->get($uid))->name ?: __('Unassigned') }}: <strong>{{ $s['avg'] }}</strong> ({{ $s['n'] }})</li>@empty<li class="text-muted">{{ __('No answers yet.') }}</li>@endforelse
  </ul>

  <h6>{{ __('Latest answers') }}</h6>
  <ul class="list-unstyled small">
    @forelse ($recent as $r)<li>{{ $r->answered_at->format('d/m H:i') }} · {{ optional($r->chat)->name ?: optional($r->chat)->phone }} · <strong>{{ $r->score }}</strong></li>@empty<li class="text-muted">{{ __('No answers yet.') }}</li>@endforelse
  </ul>
</div>
@endsection
