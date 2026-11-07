@extends('layouts.app-master')

@section('template_title', $co->name)

@section('content')
<div class="container py-4" style="max-width:980px">
  <a href="{{ route('companies.index') }}" class="small">&larr; {{ __('Companies') }}</a>
  <h4 class="mb-3 mt-1"><i class="bi bi-building me-1"></i> {{ $co->name }}</h4>
  @include('layouts.partials.messages')
  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ $contacts->count() }}</div><div class="small text-muted">{{ __('Contacts') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ $deals->where('status', 'open')->count() }}</div><div class="small text-muted">{{ __('Open deals') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ number_format((float) $summary['open'], 2, ',', '.') }}</div><div class="small text-muted">{{ __('In the funnel') }}</div></div></div>
    <div class="col-6 col-md-3"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ number_format((float) $summary['won'], 2, ',', '.') }}</div><div class="small text-muted">{{ __('Won') }}</div></div></div>
  </div>
  @teamcan('contacts.manage')
  <form method="POST" action="{{ route('companies.update', $co->id) }}" class="card p-3 mb-3">
    @csrf @method('PUT')
    <div class="row g-2 mb-2">
      <div class="col-md-4"><input name="name" class="form-control" required maxlength="160" value="{{ $co->name }}"></div>
      <div class="col-md-3"><input name="domain" class="form-control" maxlength="160" value="{{ $co->domain }}" placeholder="{{ __('Website / domain') }}"></div>
      <div class="col-md-3"><input name="industry" class="form-control" maxlength="80" value="{{ $co->industry }}" placeholder="{{ __('Industry') }}"></div>
      <div class="col-md-2"><input name="phone" class="form-control" maxlength="40" value="{{ $co->phone }}" placeholder="{{ __('Phone') }}"></div>
    </div>
    <textarea name="notes" rows="2" class="form-control mb-2" maxlength="5000" placeholder="{{ __('Notes') }}">{{ $co->notes }}</textarea>
    <div class="d-flex gap-2"><button class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>
  </form>
  <form method="POST" action="{{ route('companies.destroy', $co->id) }}" onsubmit="return confirm('{{ __('Remove this company? Contacts stay.') }}')" class="mb-3">@csrf @method('DELETE')<button class="btn btn-outline-danger btn-sm">{{ __('Remove') }}</button></form>
  @endteamcan

  <h6>{{ __('Contacts') }}</h6>
  <ul class="list-unstyled small">
    @foreach ($contacts as $c)
      <li class="d-flex gap-2"><a href="{{ route('contacts.show', $c->id) }}">{{ $c->name }}</a> <span class="text-muted">{{ $c->contact }}</span>
        @teamcan('contacts.manage')<form method="POST" action="{{ route('companies.detach', [$co->id, $c->id]) }}" class="ms-auto">@csrf @method('DELETE')<button class="btn btn-link btn-sm p-0 text-danger">×</button></form>@endteamcan</li>
    @endforeach
  </ul>
  @teamcan('contacts.manage')
  <form method="POST" action="{{ route('companies.attach', $co->id) }}" class="d-flex gap-2 mb-3">@csrf
    <select name="contact_id" class="form-select form-select-sm" required><option value="">{{ __('Add a contact') }}</option>@foreach ($free as $f)<option value="{{ $f->id }}">{{ $f->name }}</option>@endforeach</select>
    <button class="btn btn-sm btn-outline-secondary">{{ __('Add') }}</button>
  </form>
  @endteamcan

  <h6>{{ __('Deals') }}</h6>
  <ul class="list-unstyled small">
    @forelse ($deals as $d)<li>{{ $d->title }} · {{ optional($d->stage)->name }} · {{ number_format((float) $d->value, 2, ',', '.') }} · {{ $d->status }}</li>@empty<li class="text-muted">{{ __('No deals.') }}</li>@endforelse
  </ul>
</div>
@endsection
