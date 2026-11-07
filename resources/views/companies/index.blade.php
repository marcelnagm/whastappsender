@extends('layouts.app-master')

@section('template_title', __('Companies'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-3"><i class="bi bi-building me-1"></i> {{ __('Companies') }}</h4>
  @include('layouts.partials.messages')
  <form method="GET" class="mb-3"><input name="q" value="{{ $q }}" class="form-control" placeholder="{{ __('Search') }}"></form>
  @teamcan('contacts.manage')
  <form method="POST" action="{{ route('companies.store') }}" class="card p-3 mb-3">
    @csrf
    <div class="row g-2">
      <div class="col-md-4"><input name="name" class="form-control" required maxlength="160" placeholder="{{ __('Company name') }}"></div>
      <div class="col-md-3"><input name="domain" class="form-control" maxlength="160" placeholder="{{ __('Website / domain') }}"></div>
      <div class="col-md-3"><input name="industry" class="form-control" maxlength="80" placeholder="{{ __('Industry') }}"></div>
      <div class="col-md-2"><button class="btn btn-primary w-100">{{ __('Add') }}</button></div>
    </div>
  </form>
  @endteamcan
  <table class="table table-sm align-middle"><thead><tr><th>{{ __('Company') }}</th><th>{{ __('Industry') }}</th><th>{{ __('Contacts') }}</th><th>{{ __('Won') }}</th></tr></thead><tbody>
    @forelse ($companies as $c)
      <tr><td><a href="{{ route('companies.show', $c->id) }}">{{ $c->name }}</a> <span class="small text-muted">{{ $c->domain }}</span></td><td>{{ $c->industry }}</td><td>{{ $c->contacts_count }}</td><td>{{ number_format((float) ($won[$c->id] ?? 0), 2, ',', '.') }}</td></tr>
    @empty
      <tr><td colspan="4" class="text-muted">{{ __('No companies yet.') }}</td></tr>
    @endforelse
  </tbody></table>
  {{ $companies->links() }}
</div>
@endsection
