@extends('layouts.auth-master')

@section('content')
<div class="card shadow-lg border-0 rounded-lg">
    <div class="card-body p-5">
        <div class="text-center mb-4">
            {{-- Optional: replace with a radar/data themed icon --}}
            <img style="background-color: #dbc5c8; border-radius: 100%;;" src="{{ asset('images/logo.jpg') }}" alt="Logo" width="100%" height="100%" class="me-2 d-inline-block align-text-top">
            
            <h2 class="fw-bold">{{env('APP_NAME ')}}</h2>
            <p class="text-muted">Sign in to your intelligence hub</p>
        </div>

        <form method="post" action="{{ route('login.perform') }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}" />

            @include('layouts.partials.messages')

            <div class="form-floating mb-3">
                <input type="text" class="form-control border-0 bg-light" id="floatingName" name="username" 
                       value="{{ old('username') }}" placeholder="Username" required autofocus>
                <label for="floatingName">Username or email</label>
                @if ($errors->has('username'))
                    <small class="text-danger">{{ $errors->first('username') }}</small>
                @endif
            </div>
            
            <div class="form-floating mb-3">
                <input type="password" class="form-control border-0 bg-light" id="floatingPassword" name="password" 
                       placeholder="Password" required>
                <label for="floatingPassword">Password</label>
                @if ($errors->has('password'))
                    <small class="text-danger">{{ $errors->first('password') }}</small>
                @endif
            </div>

            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
                    <label class="form-check-label text-muted" for="remember">
                        Keep me signed in
                    </label>
                </div>
                {{-- Forgot password link can be added here later --}}
            </div>

            <button class="btn btn-primary btn-lg w-100 shadow-sm fw-bold py-3" type="submit">
                Sign in
            </button>
        </form>
    </div>
    <div class="card-footer bg-white border-0 text-center pb-4">
        @include('auth.partials.copy')
    </div>
</div>
@endsection