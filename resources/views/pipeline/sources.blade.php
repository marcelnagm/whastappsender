@extends('layouts.app-master')

@section('template_title', __('Lead sources'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $cur = config('crm.currency', 'R$'); $max = max(1, collect($data['rows'])->max('created')); @endphp
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-signpost-split me-2"></i>{{ __('Lead sources') }}</h1>
    <div class="d-flex gap-2">
      <form method="GET"><select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
        @foreach ([30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $k => $l)<option value="{{ $k }}" @selected($days === $k)>{{ $l }}</option>@endforeach
      </select></form>
      <a href="{{ route('pipeline.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-kanban me-1"></i>{{ __('Pipeline') }}</a>
    </div>
  </div>
  <p class="text-muted small">{{ __('Deals created in the period, grouped by where the lead came from: the channel of the conversation, the contact form with its UTM campaign, or what you typed on the deal.') }}</p>

  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Deals created') }}</div><strong>{{ $data['totals']['created'] }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Won') }}</div><strong>{{ $data['totals']['won'] }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Win rate') }}</div><strong>{{ $data['totals']['win_rate'] === null ? '—' : $data['totals']['win_rate'] . '%' }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Value won') }}</div><strong>{{ $cur }} {{ $money($data['totals']['won_value']) }}</strong></div></div></div>
  </div>

  <div class="card border-0 shadow-sm"><div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>{{ __('Origin') }}</th><th style="width:22%"></th><th class="text-end">{{ __('Created') }}</th><th class="text-end">{{ __('Open') }}</th><th class="text-end">{{ __('Won') }}</th><th class="text-end">{{ __('Lost') }}</th><th class="text-end">{{ __('Win rate') }}</th><th class="text-end">{{ __('Value won') }}</th><th class="text-end">{{ __('Avg days to win') }}</th></tr></thead>
      <tbody>
        @forelse ($data['rows'] as $r)
          <tr>
            <td class="fw-semibold">{{ $r['origin'] ?? __('Not informed') }}</td>
            <td><div class="progress" style="height:6px"><div class="progress-bar" style="width: {{ round(100 * $r['created'] / $max) }}%"></div></div></td>
            <td class="text-end">{{ $r['created'] }}</td><td class="text-end">{{ $r['open'] }}</td><td class="text-end">{{ $r['won'] }}</td><td class="text-end">{{ $r['lost'] }}</td>
            <td class="text-end">{{ $r['win_rate'] === null ? '—' : $r['win_rate'] . '%' }}</td><td class="text-end">{{ $money($r['won_value']) }}</td><td class="text-end">{{ $r['avg_days'] ?? '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="9" class="text-muted text-center py-4">{{ __('No deals in this period.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
</div>
@endsection
