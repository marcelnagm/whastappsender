@extends('layouts.app-master')

@section('template_title', __('Forms'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-10">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h1 class="h3 fw-bold mb-0"><i class="bi bi-ui-checks me-2"></i>{{ __('Contact forms') }}</h1>
      <a href="{{ route('forms.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New form') }}</a>
    </div>
    <p class="text-muted">{{ __('Build a form, paste one line of code on your website and every submission becomes a contact and a conversation in the CRM. The AI agent can answer the person right away on WhatsApp.') }}</p>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Name') }}</th><th>{{ __('Submissions') }}</th><th>{{ __('AI reply') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
          <tbody>
          @forelse ($forms as $f)
            <tr>
              <td><strong>{{ $f->name }}</strong><div class="small text-muted text-truncate" style="max-width:420px"><code>{{ $f->snippet() }}</code></div></td>
              <td>{{ $f->submissions_count }}</td>
              <td>{!! $f->ai_reply ? '<span class="badge bg-success">'.e(__('On')).'</span>' : '<span class="badge bg-secondary">'.e(__('Off')).'</span>' !!}</td>
              <td>{!! $f->active ? '<span class="badge bg-success">'.e(__('Active')).'</span>' : '<span class="badge bg-secondary">'.e(__('Inactive')).'</span>' !!}</td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('forms.submissions', $f->id) }}">{{ __('Submissions') }}</a>
                <a class="btn btn-sm btn-outline-primary" href="{{ route('forms.edit', $f->id) }}">{{ __('Edit') }}</a>
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('No forms yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>
@endsection
