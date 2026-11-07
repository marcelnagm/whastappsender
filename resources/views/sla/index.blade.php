@extends('layouts.app-master')

@section('template_title', __('Response SLA'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-stopwatch me-1"></i> {{ __('Response SLA') }}</h4>
  <p class="text-muted small">{{ __('Alerts when a customer waits too long for the first answer, and escalates to a manager.') }}</p>
  @include('layouts.partials.messages')

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $queue->where('state', '!=', 'ok')->count() }}</div><div class="small text-muted">{{ __('Overdue now') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $queue->count() }}</div><div class="small text-muted">{{ __('Waiting for an answer') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $breaches }}</div><div class="small text-muted">{{ __('Breaches (30 days)') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-3 fw-bold">{{ $escalations }}</div><div class="small text-muted">{{ __('Escalations (30 days)') }}</div></div></div>
  </div>

  <form method="POST" action="{{ route('sla.update') }}" class="card p-3 mb-3">
    @csrf @method('PUT')
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="slaOn" @checked(old('enabled', $cfg->enabled))><label class="form-check-label fw-semibold" for="slaOn">{{ __('Monitor the first response time') }}</label></div>
    <div class="row g-2 mb-2">
      <div class="col-md-4"><label class="form-label small">{{ __('Alert after (minutes)') }}</label><input type="number" name="first_response_minutes" min="1" max="1440" class="form-control" value="{{ old('first_response_minutes', $cfg->first_response_minutes) }}"></div>
      <div class="col-md-4"><label class="form-label small">{{ __('Escalate after more (minutes)') }}</label><input type="number" name="escalate_after_minutes" min="1" max="1440" class="form-control" value="{{ old('escalate_after_minutes', $cfg->escalate_after_minutes) }}"></div>
      <div class="col-md-4"><label class="form-label small">{{ __('Escalate to') }}</label>
        <select name="escalate_to" class="form-select"><option value="">{{ __('Account owner') }}</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected(old('escalate_to', $cfg->escalate_to) == $p->id)>{{ $p->name }}</option>@endforeach</select>
        @error('escalate_to')<div class="text-danger small">{{ $message }}</div>@enderror</div>
    </div>
    <div class="form-check mb-1"><input class="form-check-input" type="checkbox" name="business_only" value="1" id="slaBiz" @checked(old('business_only', $cfg->business_only))><label class="form-check-label" for="slaBiz">{{ __('Count only opening hours') }} <a href="{{ route('hours.edit') }}" class="small">({{ __('Opening hours') }})</a></label></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="reassign" value="1" id="slaRe" @checked(old('reassign', $cfg->reassign))><label class="form-check-label" for="slaRe">{{ __('Also move the conversation to that person when escalating') }}</label></div>
    <div><button class="btn btn-primary">{{ __('Save') }}</button></div>
  </form>

  <h6>{{ __('Waiting now') }}</h6>
  <div class="table-responsive mb-3"><table class="table table-sm align-middle">
    <thead><tr><th>{{ __('Conversation') }}</th><th>{{ __('Assigned to') }}</th><th>{{ __('Waiting') }}</th><th></th></tr></thead>
    <tbody>
    @forelse ($queue as $r)
      <tr>
        <td><a href="{{ route('chat.index', ['chat' => $r['chat']->id]) }}">{{ $r['chat']->name ?: $r['chat']->phone }}</a></td>
        <td>{{ optional($people->firstWhere('id', $r['chat']->assigned_to))->name ?: '—' }}</td>
        <td>{{ $r['waited'] }} min</td>
        <td>@if ($r['state'] === 'escalated')<span class="badge bg-danger">{{ __('Escalated') }}</span>@elseif ($r['state'] === 'overdue')<span class="badge bg-warning text-dark">{{ __('Overdue') }}</span>@else<span class="badge bg-success">{{ __('On time') }}</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="4" class="text-muted">{{ __('Nobody is waiting.') }}</td></tr>
    @endforelse
    </tbody></table></div>

  <h6>{{ __('Breaches by person (30 days)') }}</h6>
  <ul class="list-unstyled small">
    @forelse ($byAgent as $uid => $n)<li>{{ optional($people->firstWhere('id', $uid))->name ?: __('Unassigned') }}: <strong>{{ $n }}</strong></li>@empty<li class="text-muted">{{ __('No breaches.') }}</li>@endforelse
  </ul>
</div>
@endsection
