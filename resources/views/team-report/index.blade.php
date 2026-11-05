@extends('layouts.app-master')

@section('template_title', __('Team performance'))

@section('content')
@php
  use App\Services\Reports\TeamReport;
  $mLabel = ['deals_won' => __('Deals won'), 'won_value' => __('Value won'), 'answered' => __('Conversations answered'), 'first_response_minutes' => __('Average first response (minutes)')];
@endphp
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-trophy me-2"></i>{{ __('Team performance') }}</h1>
    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
      <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
        @foreach (['7' => __('Last 7 days'), '30' => __('Last 30 days'), '90' => __('Last 90 days'), 'month' => __('This month')] as $k => $l)
          <option value="{{ $k }}" @selected($period === $k)>{{ $l }}</option>
        @endforeach
        <option value="custom" @selected($period === 'custom')>{{ __('Custom') }}</option>
      </select>
      @if ($period === 'custom')
        <input type="date" name="from" value="{{ $data['from']->toDateString() }}" class="form-control form-control-sm">
        <input type="date" name="to" value="{{ $data['to']->toDateString() }}" class="form-control form-control-sm">
        <button class="btn btn-sm btn-primary">{{ __('Apply') }}</button>
      @endif
    </form>
  </div>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('Average first response') }}</div><div class="h4 mb-0">{{ TeamReport::human($data['totals']['avg_response']) }}</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('Conversations answered') }}</div><div class="h4 mb-0">{{ $data['totals']['answered'] }}</div><div class="small text-muted">{{ __(':n answered by the AI agent', ['n' => $data['ai_answered']]) }}</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('Deals won') }}</div><div class="h4 mb-0 text-success">{{ $data['totals']['won'] }}</div><div class="small text-muted">{{ number_format($data['totals']['won_value'], 2, ',', '.') }}</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">{{ __('Follow-ups done') }}</div><div class="h4 mb-0">{{ $data['totals']['tasks_done'] }}</div></div></div></div>
  </div>

  <div class="card border-0 shadow-sm mb-3"><div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>{{ __('Person') }}</th><th class="text-end">{{ __('Answered') }}</th><th class="text-end">{{ __('Messages sent') }}</th><th class="text-end">{{ __('Avg first response') }}</th><th class="text-end">{{ __('Median') }}</th><th class="text-end">{{ __('Won') }}</th><th class="text-end">{{ __('Value won') }}</th><th class="text-end">{{ __('Lost') }}</th><th class="text-end">{{ __('Follow-ups done') }}</th><th class="text-end">{{ __('Overdue') }}</th></tr></thead>
      <tbody>
        @forelse ($data['people'] as $r)
          <tr>
            <td class="fw-semibold">{{ $r['name'] }}</td>
            <td class="text-end">{{ $r['answered'] }}</td><td class="text-end">{{ $r['messages'] }}</td>
            <td class="text-end">{{ TeamReport::human($r['avg_response']) }}</td><td class="text-end">{{ TeamReport::human($r['median_response']) }}</td>
            <td class="text-end">{{ $r['won'] }}</td><td class="text-end">{{ number_format($r['won_value'], 2, ',', '.') }}</td><td class="text-end">{{ $r['lost'] }}</td>
            <td class="text-end">{{ $r['tasks_done'] }}</td><td class="text-end @if ($r['tasks_overdue']) text-danger fw-bold @endif">{{ $r['tasks_overdue'] }}</td>
          </tr>
        @empty
          <tr><td colspan="10" class="text-muted text-center py-4">{{ __('No activity in this period.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
  <p class="small text-muted">{{ __('First response is how long a customer waited until the next reply. Replies by the AI agent and campaigns close the wait without counting for any person; messages typed on the phone count for the person the conversation is assigned to.') }}</p>

  <div class="card border-0 shadow-sm"><div class="card-body">
    <h6 class="fw-bold"><i class="bi bi-bullseye me-1"></i>{{ __('Goals of the month') }}</h6>
    @forelse ($goals as $g)
      <div class="mb-2">
        <div class="d-flex justify-content-between small">
          <span>{{ $g['member'] }} · {{ $mLabel[$g['metric']] }}</span>
          <span>{{ $g['value'] }} / {{ $g['target'] }} @if ($g['reached'])<i class="bi bi-check-circle-fill text-success"></i>@endif
            @teamcan('team')<form method="POST" action="{{ route('team-report.goals.destroy', $g['id']) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-link btn-sm text-danger p-0 ms-1"><i class="bi bi-x"></i></button></form>@endteamcan</span>
        </div>
        @if ($g['pct'] !== null)<div class="progress" style="height:6px"><div class="progress-bar {{ $g['reached'] ? 'bg-success' : '' }}" style="width: {{ $g['pct'] }}%"></div></div>@endif
      </div>
    @empty
      <p class="text-muted small mb-2">{{ __('No goals yet.') }}</p>
    @endforelse
    @teamcan('team')
    <form method="POST" action="{{ route('team-report.goals.store') }}" class="row g-2 mt-2">@csrf
      <div class="col-md-4"><select name="member_id" class="form-select form-select-sm"><option value="0">{{ __('Whole team') }}</option>@foreach ($people as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach</select></div>
      <div class="col-md-4"><select name="metric" class="form-select form-select-sm">@foreach ($metrics as $m)<option value="{{ $m }}">{{ $mLabel[$m] }}</option>@endforeach</select></div>
      <div class="col-md-2"><input type="number" step="any" min="0.01" name="target" class="form-control form-control-sm" placeholder="{{ __('Target') }}" required></div>
      <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">{{ __('Save goal') }}</button></div>
    </form>
    @endteamcan
  </div></div>
</div>
@endsection
