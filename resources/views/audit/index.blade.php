@extends('layouts.app-master')

@section('template_title', __('Audit log'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-shield-lock me-1"></i> {{ __('Audit log') }}</h4>
  <p class="text-muted small">{{ __('Who changed what: deletions, team, integrations and settings. Contents are never stored.') }}</p>
  <form method="GET" class="row g-2 mb-3">
    <div class="col-md-4"><select name="actor" class="form-select form-select-sm"><option value="">{{ __('Everyone') }}</option>@foreach ($actors as $a)<option value="{{ $a->actor_id }}" @selected($actor == $a->actor_id)>{{ $a->actor_name }}</option>@endforeach</select></div>
    <div class="col-md-6"><input name="q" value="{{ $q }}" class="form-control form-control-sm" placeholder="{{ __('Action (for example contacts.destroy)') }}"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-secondary w-100">{{ __('Filter') }}</button></div>
  </form>
  <table class="table table-sm small align-middle"><thead><tr><th>{{ __('When') }}</th><th>{{ __('Person') }}</th><th>{{ __('Action') }}</th><th>{{ __('Item') }}</th><th>IP</th></tr></thead><tbody>
    @forelse ($logs as $l)
      <tr><td>{{ $l->created_at->format('d/m/Y H:i:s') }}</td><td>{{ $l->actor_name }}</td><td><code>{{ $l->action }}</code> <span class="text-muted">{{ $l->method }}</span></td><td>{{ $l->subject }}</td><td>{{ $l->ip }}</td></tr>
    @empty
      <tr><td colspan="5" class="text-muted">{{ __('Nothing recorded yet.') }}</td></tr>
    @endforelse
  </tbody></table>
  {{ $logs->links() }}
</div>
@endsection
