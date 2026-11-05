@extends('layouts.app-master')

@section('template_title', __('Quick replies'))

@section('content')
@php
  $varsHelp = collect($variables)->map(fn ($v) => '{' . '{' . $v . '}' . '}')->implode(' ');
  $example = 'Hi {' . '{first_name}' . '}, here is our price table…';
@endphp
<div class="container py-4">
  <h1 class="h3 fw-bold mb-1"><i class="bi bi-lightning-charge me-2"></i>{{ __('Quick replies') }}</h1>
  <p class="text-muted">{{ __('In the chat, type "/" and the shortcut to insert the text. Variables: :vars.', ['vars' => $varsHelp]) }}</p>

  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <form method="POST" action="{{ route('replies.store') }}" class="card card-body border-0 shadow-sm mb-4">
    @csrf
    <div class="row g-2">
      <div class="col-md-3"><label class="form-label small mb-1">{{ __('Shortcut') }}</label>
        <div class="input-group"><span class="input-group-text">/</span><input name="shortcut" class="form-control" maxlength="40" required placeholder="price"></div></div>
      <div class="col-md-9"><label class="form-label small mb-1">{{ __('Title') }}</label><input name="title" class="form-control" maxlength="80" required placeholder="{{ __('Price table') }}"></div>
      <div class="col-12"><label class="form-label small mb-1">{{ __('Text') }}</label><textarea name="body" class="form-control" rows="3" maxlength="4000" required placeholder="{{ __($example) }}"></textarea></div>
      <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>{{ __('Add') }}</button></div>
    </div>
  </form>

  <div class="card border-0 shadow-sm">
    <ul class="list-group list-group-flush">
      @forelse ($replies as $r)
        <li class="list-group-item">
          <details>
            <summary class="d-flex justify-content-between gap-3" style="cursor:pointer">
              <span><code>/{{ $r->shortcut }}</code> <strong class="ms-2">{{ $r->title }}</strong></span>
              <span class="small text-muted">{{ trans_choice(':n use|:n uses', $r->uses, ['n' => $r->uses]) }}</span>
            </summary>
            <form method="POST" action="{{ route('replies.update', $r->id) }}" class="row g-2 mt-2">@csrf @method('PUT')
              <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">/</span><input name="shortcut" class="form-control" value="{{ $r->shortcut }}" maxlength="40" required></div></div>
              <div class="col-md-9"><input name="title" class="form-control form-control-sm" value="{{ $r->title }}" maxlength="80" required></div>
              <div class="col-12"><textarea name="body" class="form-control form-control-sm" rows="3" maxlength="4000" required>{{ $r->body }}</textarea></div>
              <div class="col-12 d-flex gap-2">
                <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                <button class="btn btn-sm btn-outline-danger" form="del-{{ $r->id }}" onclick="return confirm(@js(__('Remove this quick reply?')))">{{ __('Remove') }}</button>
              </div>
            </form>
            <form id="del-{{ $r->id }}" method="POST" action="{{ route('replies.destroy', $r->id) }}">@csrf @method('DELETE')</form>
          </details>
        </li>
      @empty
        <li class="list-group-item text-muted text-center py-4">{{ __('Nothing here.') }}</li>
      @endforelse
    </ul>
  </div>
</div>
@endsection
