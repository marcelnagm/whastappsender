@extends('layouts.app-master')

@section('template_title', __('Win/loss analysis'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); @endphp
<div class="container py-4" style="max-width:980px">
  <a href="{{ route('pipeline.index') }}" class="small">&larr; {{ __('Funnel') }}</a>
  <h4 class="mb-1 mt-1"><i class="bi bi-trophy me-1"></i> {{ __('Win/loss analysis') }}</h4>
  <p class="text-muted small">{{ __('Deals closed in the period: why they were lost and who wins more.') }}</p>
  @include('layouts.partials.messages')
  <div class="mb-3">
    @foreach ([30, 90, 365] as $p)<a href="{{ route('pipeline.winloss', ['period' => $p]) }}" class="btn btn-sm {{ $days === $p ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $p }} {{ __('days') }}</a>@endforeach
  </div>
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Won') }}</div><strong>{{ $data['won'] }} · {{ $money($data['won_value']) }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Lost') }}</div><strong>{{ $data['lost'] }} · {{ $money($data['lost_value']) }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Win rate') }}</div><strong>{{ $data['win_rate'] !== null ? $data['win_rate'] . '%' : '—' }}</strong></div></div></div>
  </div>

  <h6>{{ __('Lost reasons') }}</h6>
  <table class="table table-sm align-middle"><thead><tr><th>{{ __('Reason') }}</th><th>{{ __('Deals') }}</th><th>%</th><th>{{ __('Value') }}</th></tr></thead><tbody>
    @forelse ($data['reasons'] as $r)
      <tr><td>{{ $r['reason'] ?: __('No reason given') }}</td><td>{{ $r['count'] }}</td><td>{{ $r['share'] }}%</td><td>{{ $money($r['value']) }}</td></tr>
    @empty
      <tr><td colspan="4" class="text-muted">{{ __('No lost deals in the period.') }}</td></tr>
    @endforelse
  </tbody></table>

  @foreach ([['by_origin', __('By lead origin')], ['by_owner', __('By person in charge')]] as [$k, $label])
    <h6 class="mt-4">{{ $label }}</h6>
    <table class="table table-sm align-middle"><thead><tr><th></th><th>{{ __('Won') }}</th><th>{{ __('Lost') }}</th><th>{{ __('Win rate') }}</th></tr></thead><tbody>
      @forelse ($data[$k] as $r)
        <tr><td>{{ $r['key'] ?? __('Not informed') }}</td><td>{{ $r['won'] }}</td><td>{{ $r['lost'] }}</td><td>{{ $r['win_rate'] !== null ? $r['win_rate'] . '%' : '—' }}</td></tr>
      @empty
        <tr><td colspan="4" class="text-muted">—</td></tr>
      @endforelse
    </tbody></table>
  @endforeach

  <h6 class="mt-4">{{ __('Reasons offered when losing a deal') }}</h6>
  <form method="POST" action="{{ route('pipeline.lost-reasons.store') }}" class="d-flex gap-2 mb-2">@csrf
    <input name="name" maxlength="120" required class="form-control form-control-sm" placeholder="{{ __('E.g. Price too high') }}">
    <button class="btn btn-primary btn-sm">{{ __('Add') }}</button>
  </form>
  @foreach ($catalog as $c)
    <form method="POST" action="{{ route('pipeline.lost-reasons.destroy', $c->id) }}" class="d-inline">@csrf @method('DELETE')
      <span class="badge text-bg-light border">{{ $c->name }} <button class="btn btn-sm p-0 border-0 text-danger" title="{{ __('Delete') }}">&times;</button></span>
    </form>
  @endforeach
</div>
@endsection
