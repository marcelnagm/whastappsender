@extends('layouts.app-master')

@section('template_title', __('Priorities'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $cls = ['hot' => 'success', 'warm' => 'warning', 'cold' => 'secondary']; @endphp
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-stars me-2"></i>{{ __('Priorities') }}</h1>
    <a href="{{ route('pipeline.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-kanban me-1"></i>{{ __('Pipeline') }}</a>
  </div>
  <p class="text-muted small">{{ __('Open deals ranked by chance to close. The percentage is a transparent estimate from the stage reached, how recently the customer wrote, follow-ups, age and dates; it is not a promise. Hover or open a deal to see why.') }}</p>

  <div class="row g-2 mb-3">
    <div class="col-6"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('In progress') }}</div><strong>{{ $money($total) }}</strong></div></div></div>
    <div class="col-6"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Forecast') }}</div><strong>{{ $money($weighted) }}</strong></div></div></div>
  </div>

  <div class="card border-0 shadow-sm"><div class="list-group list-group-flush">
    @forelse ($deals as $d)
      @php $f = $scores[$d->id]; @endphp
      <div class="list-group-item">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div>
            <span class="badge bg-{{ $cls[$f['level']] }} me-1">{{ $f['score'] }}%</span><strong>{{ $d->title }}</strong>
            <span class="text-muted small">· {{ optional($d->stage)->name }} · {{ $money($d->value) }}@if ($d->contact) · {{ $d->contact->name }}@endif</span>
            <div class="small"><i class="bi bi-arrow-right-circle me-1 text-primary"></i>{{ $f['next'] }}</div>
            <div class="small text-muted">@foreach ($f['reasons'] as $r)<span class="me-2">{{ $r['delta'] > 0 ? '+' : '' }}{{ $r['delta'] }} {{ $r['text'] }}</span>@endforeach</div>
            <div class="small mt-1 d-none text-break" data-advice="{{ $d->id }}"></div>
          </div>
          <button class="btn btn-sm btn-outline-primary text-nowrap" data-ask="{{ $d->id }}"><i class="bi bi-robot me-1"></i>{{ __('Ask AI') }}</button>
        </div>
      </div>
    @empty
      <div class="list-group-item text-muted">{{ __('No open deals.') }}</div>
    @endforelse
  </div></div>
</div>
<script>
document.querySelectorAll('[data-ask]').forEach(function (b) {
  b.addEventListener('click', function () {
    var id = b.dataset.ask, box = document.querySelector('[data-advice="' + id + '"]');
    b.disabled = true; box.classList.remove('d-none'); box.textContent = '…';
    fetch(@json(url('pipeline/deals')) + '/' + id + '/advice', { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) } })
      .then(function (r) { return r.json(); })
      .then(function (d) { box.textContent = d.text || d.error || ''; })
      .catch(function () { box.textContent = @json(__('The AI agent could not answer now.')); })
      .finally(function () { b.disabled = false; });
  });
});
</script>
@endsection
