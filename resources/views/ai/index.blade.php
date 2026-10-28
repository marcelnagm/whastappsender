@extends('layouts.app-master')

@section('template_title', __('AI agents'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-10">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <h1 class="h3 mb-0 fw-bold"><i class="bi bi-robot text-primary me-2"></i>{{ __('AI agents') }}</h1>
      <a href="{{ route('ai.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New agent') }}</a>
    </div>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif

    <div class="card shadow-sm mb-4"><div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <div class="fw-semibold">{{ __('Automatic AI replies') }}</div>
        <div class="small text-muted">{{ __('The agent answers incoming messages through the same WhatsApp number that received them.') }}</div>
      </div>
      <form method="POST" action="{{ route('ai.toggle') }}" class="form-check form-switch m-0">
        @csrf
        <input type="hidden" name="enabled" value="0">
        <input class="form-check-input" type="checkbox" role="switch" name="enabled" value="1" id="masterSwitch" {{ $enabled ? 'checked' : '' }} onchange="this.form.submit()">
        <label class="form-check-label" for="masterSwitch">{{ $enabled ? __('On') : __('Off') }}</label>
      </form>
    </div></div>

    <div class="card shadow-sm mb-4">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr>
            <th>{{ __('Agent') }}</th><th>{{ __('Answers for') }}</th><th>{{ __('Provider / model') }}</th><th>{{ __('Hours') }}</th><th>{{ __('Status') }}</th><th></th>
          </tr></thead>
          <tbody>
          @forelse ($profiles as $p)
            <tr>
              <td class="fw-semibold">{{ $p->name }}</td>
              <td>{{ $p->instance ? ($p->instance->name ?? $p->instance->instance_name) : __('Any instance') }}</td>
              <td>{{ $p->providerLabel() }}<div class="small text-muted">{{ $p->model }}</div></td>
              <td class="small">
                @if ($p->business_hours_only)
                  {{ $p->business_start ?: sprintf('%02d:00', config('ai.business_hours.start')) }}–{{ $p->business_end ?: sprintf('%02d:00', config('ai.business_hours.end')) }}
                @else {{ __('Always') }} @endif
              </td>
              <td>@if ($p->enabled)<span class="badge bg-success">{{ __('Enabled') }}</span>@else<span class="badge bg-secondary">{{ __('Disabled') }}</span>@endif</td>
              <td class="text-end text-nowrap">
                <a href="{{ route('ai.edit', $p) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                <form method="POST" action="{{ route('ai.destroy', $p) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove this agent?') }}')">
                  @csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No agents yet. Create one to start answering automatically.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>

    @if ($failures->isNotEmpty())
    <div class="card shadow-sm mb-4 border-warning"><div class="card-header bg-warning-subtle fw-semibold">{{ __('Recent AI errors') }}</div>
      <ul class="list-group list-group-flush small">
        @foreach ($failures as $f)<li class="list-group-item"><span class="text-muted">{{ $f->created_at }}</span> · {{ $f->provider }} {{ $f->model }} — {{ $f->error }}</li>@endforeach
      </ul>
    </div>
    @endif

    <p class="small text-muted">{{ __('An agent tied to one instance wins over the "any instance" agent. A disabled agent tied to an instance keeps the AI silent on that number.') }}</p>

  </div></div>
</div>
@endsection
