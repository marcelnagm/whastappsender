@extends('layouts.app-master')

@section('template_title', __('Renewals'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $labels = \App\Services\Pipeline\Renewals::labels(); @endphp
<div class="container py-4" style="max-width:980px">
  <a href="{{ route('pipeline.index') }}" class="small">&larr; {{ __('Funnel') }}</a>
  <h4 class="mb-1 mt-1"><i class="bi bi-arrow-repeat me-1"></i> {{ __('Renewals') }}</h4>
  <p class="text-muted small">{{ __('Won deals with a billing cycle are subscriptions. Fifteen days before the renewal date a task is created for the person in charge. Mark each one as renewed or cancelled.') }}</p>
  @include('layouts.partials.messages')
  <div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">MRR</div><strong>{{ $money($sum['mrr']) }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">ARR</div><strong>{{ $money($sum['arr']) }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Active subscriptions') }}</div><strong>{{ $sum['count'] }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body py-2"><div class="small text-muted">{{ __('Cancelled') }}</div><strong>{{ $sum['churned'] }}</strong></div></div></div>
  </div>
  <div class="mb-3">@foreach ([30, 60, 90, 365] as $d)<a href="{{ route('pipeline.renewals', ['days' => $d]) }}" class="btn btn-sm {{ $days === $d ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $d }} {{ __('days') }}</a>@endforeach</div>
  <table class="table table-sm align-middle"><thead><tr><th>{{ __('Deal') }}</th><th>{{ __('Contact') }}</th><th>{{ __('Cycle') }}</th><th>{{ __('Value') }}</th><th>{{ __('Renews') }}</th><th></th></tr></thead><tbody>
    @forelse ($rows as $d)
      @php $late = $d->renews_at->isPast() && !$d->renews_at->isToday(); @endphp
      <tr><td>{{ $d->title }}</td><td>{{ optional($d->contact)->name ?: '—' }}</td><td>{{ $labels[$d->billing_cycle] ?? $d->billing_cycle }}</td><td>{{ $money($d->value) }}</td>
        <td class="{{ $late ? 'text-danger fw-bold' : '' }}">{{ $d->renews_at->format('d/m/Y') }}@if ($late) <span class="badge text-bg-danger">{{ __('Overdue') }}</span>@endif</td>
        <td class="text-end text-nowrap">
          <form method="POST" action="{{ route('pipeline.renewals.renew', $d->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-success">{{ __('Renewed') }}</button></form>
          <form method="POST" action="{{ route('pipeline.renewals.cancel', $d->id) }}" class="d-inline" onsubmit="return confirm(@json(__('Cancel this subscription?')))">@csrf<button class="btn btn-sm btn-outline-danger">{{ __('Cancel') }}</button></form>
        </td></tr>
    @empty
      <tr><td colspan="6" class="text-muted">{{ __('Nothing renews in the period.') }}</td></tr>
    @endforelse
  </tbody></table>
</div>
@endsection
