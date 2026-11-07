@extends('layouts.app-master')

@section('template_title', __('Stalled deals'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <a href="{{ route('pipeline.index') }}" class="small">&larr; {{ __('Funnel') }}</a>
  <h4 class="mb-1 mt-1"><i class="bi bi-hourglass-split me-1"></i> {{ __('Stalled deals') }}</h4>
  <p class="text-muted small">{{ __('Open deals without changes or messages for some days. The person in charge is notified once per stalled period.') }}</p>
  @include('layouts.partials.messages')
  <form method="POST" action="{{ route('pipeline.stale.update') }}" class="card p-3 mb-3 d-flex flex-row flex-wrap gap-3 align-items-center">
    @csrf @method('PUT')
    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="stOn" @checked($cfg->enabled)><label class="form-check-label" for="stOn">{{ __('Notify about stalled deals') }}</label></div>
    <div class="small">{{ __('Stalled after') }} <input type="number" name="days" min="1" max="365" value="{{ old('days', $cfg->days) }}" class="form-control form-control-sm d-inline-block" style="width:80px"> {{ __('days') }}</div>
    <button class="btn btn-primary btn-sm">{{ __('Save') }}</button>
  </form>
  <table class="table table-sm align-middle"><thead><tr><th>{{ __('Deal') }}</th><th>{{ __('Stage') }}</th><th>{{ __('Value') }}</th><th>{{ __('Idle') }}</th></tr></thead><tbody>
    @forelse ($rows as $r)
      <tr><td><a href="{{ route('pipeline.index', ['pipeline' => $r['deal']->pipeline_id]) }}">{{ $r['deal']->title }}</a> <span class="small text-muted">{{ optional($r['deal']->contact)->name }}</span></td><td>{{ optional($r['deal']->stage)->name }}</td><td>{{ number_format((float) $r['deal']->value, 2, ',', '.') }}</td><td>{{ $r['idle'] }} {{ __('days') }}</td></tr>
    @empty
      <tr><td colspan="4" class="text-muted">{{ __('No stalled deals.') }}</td></tr>
    @endforelse
  </tbody></table>
</div>
@endsection
