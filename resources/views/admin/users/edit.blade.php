@extends('layouts.app-master')

@section('template_title', 'Edit user - ' . $user->name)

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex align-items-center mb-4 gap-3">
                <a href="{{ route('users.index') }}" class="btn btn-outline-secondary btn-sm rounded-circle">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-person-gear text-primary me-2"></i>Edit user profile
                </h1>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Account details: {{ $user->name }}</h6>
                </div>

                <div class="card-body p-4">
                    @if ($errors->any())
                        <div class="alert alert-danger border-0 mb-4">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li><i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('users.update', $user->id) }}" role="form">
                        @csrf
                        @method('POST') {{-- Routes use POST for update --}}

                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label small fw-bold text-uppercase">Full name</label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $user->name) }}" placeholder="e.g. Jane Smith">
                            </div>

                            <div class="col-md-6">
                                <label for="username" class="form-label small fw-bold text-uppercase">Username (login)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">@</span>
                                    <input type="text" name="username" id="username" class="form-control" value="{{ old('username', $user->username) }}">
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="email" class="form-label small fw-bold text-uppercase">Official email</label>
                                <input type="email" name="email" id="email" class="form-control" value="{{ old('email', $user->email) }}">
                            </div>

                            <hr class="my-4 text-muted">

                            <div class="col-md-12">
                                <div class="alert alert-light border-0 small mb-3">
                                    <i class="bi bi-info-circle me-2 text-primary"></i> 
                                    Leave the password fields <strong>blank</strong> if you do not want to change the password.
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label for="password" class="form-label small fw-bold text-uppercase">New password</label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="••••••••">
                            </div>

                            <div class="col-md-6">
                                <label for="password_confirmation" class="form-label small fw-bold text-uppercase">Confirm new password</label>
                                <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" placeholder="••••••••">
                            </div>

                            <div class="col-md-12 mt-5 d-flex justify-content-between">
                                <button type="button" class="btn btn-link text-danger fw-bold text-decoration-none" onclick="window.history.back()">
                                    Cancel
                                </button>
                                <button type="submit" class="btn btn-primary px-5 shadow-sm fw-bold">
                                    <i class="bi bi-check2-circle me-2"></i>Save changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="mt-4 text-center">
                <small class="text-muted">
                    User created: <strong>{{ $user->created_at->format('d/m/Y H:i') }}</strong> | 
                    Last updated: <strong>{{ $user->updated_at->format('d/m/Y H:i') }}</strong>
                </small>
            </div>
        </div>
    </div>
</div>
@endsection