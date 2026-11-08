@extends('layouts.app-master')

@section('template_title', __('Funnel conversion'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $max = max(1, collect($data['rows'])->max('reached')); @endphp
<div class="container py-4" style="max-width:980px">
  <a href="{{ route('pipeline.index', ['pipeline' => $pipeline->id]) }}" class="small">&larr; {{ __('Funnel') }}</a>
  <h4 class="mb-1 mt-1"><i class="bi bi-funnel me-1"></i> {{ __('Funnel conversion') }}</h4>
  <p class="text-muted small">{{ __('How many deals reach each stage, how many move on, how long they stay and how fast the pipeline turns into revenue. History starts when this feature was installed.') }}</p>
  <form class="d-flex gap-2 mb-3" method="GET">
    <select name="pipeline" class="form-select form-select-sm w-auto" onchange="this.form.submit()">@foreach ($pipelines as $p)<option value="{{ $p->id }}" @selected($p->id === $pipeline->id)>{{ $p->name }}</option>@endforeach</select>
    <select name="period" class="form-select form-select-sm w-auto" onchange="this.form.submit()">@foreach ([30, 90, 365] as $d)<option value="{{ $d }}" @selected($d === $days)>{{ $d }} {{ __('days') }}</option>@endforeach</select>
  </form>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Win rate') }}</div><strong>{{ $data['win_rate'] !== null ? $data['win_rate'] . '%' : '—' }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Average deal') }}</div><strong>{{ $data['avg_value'] !== null ? $money($data['avg_value']) : '—' }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Sales cycle') }}</div><strong>{{ $data['cycle_days'] !== null ? $data['cycle_days'] . ' ' . __('days') : '—' }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Velocity per day') }}</div><strong>{{ $data['velocity_day'] !== null ? $money($data['velocity_day']) : '—' }}</strong></div></div></div>
  </div>
  <table class="table table-sm align-middle"><thead><tr><th>{{ __('Stage') }}</th><th style="width:38%">{{ __('Deals that reached it') }}</th><th>{{ __('Moved on') }}</th><th>{{ __('Conversion') }}</th><th>{{ __('Avg. days in stage') }}</th></tr></thead><tbody>
    @forelse ($data['rows'] as $r)
      <tr><td>{{ $r['stage'] }}</td>
        <td><div class="d-flex align-items-center gap-2"><div class="rounded" style="height:14px;width:{{ round(100 * $r['reached'] / $max) }}%;background:{{ $r['color'] ?: '#6c757d' }};min-width:2px"></div><span class="small">{{ $r['reached'] }}</span></div></td>
        <td>{{ $r['advanced'] }}</td><td>{{ $r['conversion'] !== null ? $r['conversion'] . '%' : '—' }}</td><td>{{ $r['avg_days'] ?? '—' }}</td></tr>
    @empty
      <tr><td colspan="5" class="text-muted">—</td></tr>
    @endforelse
  </tbody></table>
  <p class="small text-muted">{{ __('Velocity = open deals × win rate × average deal ÷ sales cycle (revenue the pipeline tends to produce per day).') }} {{ __('Open deals') }}: {{ $data['open'] }}.</p>
</div>
@endsection
