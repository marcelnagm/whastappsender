@extends('layouts.app-master')

@section('template_title', __('Submissions'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-10">
    <a href="{{ route('forms.index') }}" class="small text-decoration-none">&larr; {{ __('Forms') }}</a>
    <h1 class="h3 fw-bold mb-3">{{ $form->name }} <small class="text-muted fs-6">{{ __('Submissions') }}</small></h1>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Date') }}</th><th>{{ __('Answers') }}</th><th>{{ __('Channel') }}</th><th>{{ __('Status') }}</th></tr></thead>
          <tbody>
          @forelse ($submissions as $s)
            <tr>
              <td class="text-nowrap small">{{ $s->created_at->format('d/m/Y H:i') }}</td>
              <td class="small">
                @foreach ((array) $form->fields as $f)
                  @if (($s->data[$f['key']] ?? '') !== '')<div><strong>{{ $f['label'] }}:</strong> {{ $s->data[$f['key']] }}</div>@endif
                @endforeach
                @if ($s->contact)<div class="mt-1"><a href="{{ route('contacts.show', $s->contact_id) }}">{{ __('Open contact') }}</a></div>@endif
              </td>
              <td>
                @if ($s->delivery && $s->delivery !== 'none')<span class="badge bg-success">{{ ucfirst($s->delivery) }}</span>@if ($s->ai_requested) <span class="badge bg-info text-dark">AI</span>@endif
                @else<span class="badge bg-secondary">{{ __('Stored only') }}</span>@endif
              </td>
              <td class="small">{{ $s->status }}@if ($s->note)<div class="text-muted">{{ $s->note }}</div>@endif</td>
            </tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('No submissions yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
    <div class="mt-3">{{ $submissions->links() }}</div>
  </div></div>
</div>
@endsection
