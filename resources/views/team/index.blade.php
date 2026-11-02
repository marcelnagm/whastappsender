@extends('layouts.app-master')

@section('template_title', __('Team'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-10">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-people-fill me-2"></i>{{ __('Team') }}</h1>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">{{ __('Add a team member') }}</h2>
      <p class="small text-muted mb-3">{{ __('Members sign in with their own login and work on this account, limited by their role. Plan, contacts and channels stay yours.') }}</p>
      <form method="POST" action="{{ route('team.store') }}" class="row g-2">
        @csrf
        <div class="col-md-3"><input name="name" class="form-control" placeholder="{{ __('Name') }}" value="{{ old('name') }}" required></div>
        <div class="col-md-3"><input type="email" name="email" class="form-control" placeholder="{{ __('Email') }}" value="{{ old('email') }}" required></div>
        <div class="col-md-2"><input type="password" name="password" class="form-control" placeholder="{{ __('Password') }}" autocomplete="new-password" required></div>
        <div class="col-md-2">
          <select name="role" class="form-select" required>
            @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected(old('role') === $key)>{{ $label }}</option>@endforeach
          </select>
        </div>
        <div class="col-md-2 d-grid"><button class="btn btn-primary">{{ __('Add') }}</button></div>
      </form>
      <ul class="small text-muted mt-3 mb-0">
        @foreach ($roles as $key => $label)<li><strong>{{ $label }}:</strong> {{ $descriptions[$key] }}</li>@endforeach
      </ul>
    </div></div>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Name') }}</th><th>{{ __('Role') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
          <tbody>
          @forelse ($members as $m)
            @php($self = $me && $me->id === $m->id)
            <tr>
              <td><strong>{{ $m->name }}</strong><div class="small text-muted">{{ $m->email }}</div></td>
              <td>
                <select name="role" form="u{{ $m->id }}" class="form-select form-select-sm" @disabled($self)>
                  @foreach ($roles as $key => $label)<option value="{{ $key }}" @selected($m->team_role === $key)>{{ $label }}</option>@endforeach
                </select>
              </td>
              <td>
                <div class="form-check"><input type="checkbox" form="u{{ $m->id }}" class="form-check-input" name="active" value="1" id="a{{ $m->id }}" @checked($m->active) @disabled($self)><label class="form-check-label small" for="a{{ $m->id }}">{{ __('Active') }}</label></div>
                <input type="password" form="u{{ $m->id }}" name="password" class="form-control form-control-sm mt-1" placeholder="{{ __('New password') }}" autocomplete="new-password" @disabled($self)>
              </td>
              <td class="text-end text-nowrap">
                @if ($self)
                  <span class="small text-muted">{{ __('You') }}</span>
                @else
                  <button form="u{{ $m->id }}" class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button>
                  <button form="d{{ $m->id }}" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __('Remove this team member?') }}')">{{ __('Remove') }}</button>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('No team members yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
    @foreach ($members as $m)
      <form id="u{{ $m->id }}" method="POST" action="{{ route('team.update', $m->id) }}">@csrf @method('PUT')</form>
      <form id="d{{ $m->id }}" method="POST" action="{{ route('team.destroy', $m->id) }}">@csrf @method('DELETE')</form>
    @endforeach
  </div></div>
</div>
@endsection
