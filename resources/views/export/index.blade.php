@extends('layouts.app-master')

@section('template_title', __('Data export'))

@section('content')
<div class="container py-4" style="max-width:760px">
  <h4 class="mb-1"><i class="bi bi-download me-1"></i> {{ __('Data export') }}</h4>
  <p class="text-muted small">{{ __('Download a CSV backup of your data (UTF-8, separator ;). Every download is recorded in the audit log.') }}</p>
  <div class="list-group">
    @foreach ($types as $key => $label)
      <a class="list-group-item list-group-item-action d-flex justify-content-between" href="{{ route('export.download', $key) }}"><span>{{ $label }}</span><span class="text-muted small">CSV <i class="bi bi-download"></i></span></a>
    @endforeach
  </div>
  <p class="small text-muted mt-3">{{ __('Messages: up to :n per file.', ['n' => number_format(\App\Services\Export\DataExport::MAX_MESSAGES, 0, ',', '.')]) }}</p>
</div>
@endsection
