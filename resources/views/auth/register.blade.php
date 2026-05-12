@extends('layouts.auth-master')

@section('content')
<div class="auth-card shadow-lg border-0 rounded-4 overflow-hidden">
    <div class="card-body  text-center">
        <form method="post" action="{{ route('register.perform') }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}" />

            <div class="logo-wrapper mb-4 text-center">
                <img style="background-color: #dbc5c8; border-radius: 100%;"
                    src="{{ asset('images/logo.jpg') }}"
                    alt="Logo" width="100" height="100"
                    class="mx-auto d-block shadow-sm">
            </div>

            <h1 class="h2 mb-1 fw-bold text-white">Create account</h1>
            <p class="text-white-50 mb-4">Register to request professional access</p>

            <div class="form-floating mb-5 text-start">
                <input type="text" class="form-control form-control-lg {{ $errors->has('name') ? 'is-invalid' : '' }}"
                    name="name" value="{{ old('name') }}" placeholder="Full name" required autofocus>
                <label class="ps-3">Full name</label>
                @if ($errors->has('name'))
                <div class="invalid-feedback ps-1">{{ $errors->first('name') }}</div>
                @endif
            </div>

            <div class="form-floating mb-3 text-start">
                <input type="email" class="form-control form-control-lg {{ $errors->has('email') ? 'is-invalid' : '' }}"
                    name="email" value="{{ old('email') }}" placeholder="name@example.com" required>
                <label class="ps-3">Work email</label>
                @if ($errors->has('email'))
                <div class="invalid-feedback ps-1">{{ $errors->first('email') }}</div>
                @endif
            </div>

            <div class="row g-2 mb-3">
                <div class="col-4">
                    <div class="form-floating text-start">
                        <select class="form-select form-control-lg {{ $errors->has('ddi') ? 'is-invalid' : '' }}"
                            name="ddi" id="ddiSelect" required style="padding-top: 1.625rem;">
                            <option value="55" {{ old('ddi') == '55' ? 'selected' : '' }}>🇧🇷 +55</option>
                            <option value="1" {{ old('ddi') == '1' ? 'selected' : '' }}>🇺🇸 +1</option>
                            <option value="351" {{ old('ddi') == '351' ? 'selected' : '' }}>🇵🇹 +351</option>
                            <option value="54" {{ old('ddi') == '54' ? 'selected' : '' }}>🇦🇷 +54</option>
                            <option value="595" {{ old('ddi') == '595' ? 'selected' : '' }}>🇵🇾 +595</option>
                        </select>
                        <label class="ps-3" for="ddiSelect">Country code</label>
                        @if ($errors->has('ddi'))
                        <div class="invalid-feedback ps-1" style="font-size: 0.7rem;">{{ $errors->first('ddi') }}</div>
                        @endif
                    </div>
                </div>

                <div class="col-8">
                    <div class="form-floating text-start">
                        <input type="text" class="form-control form-control-lg {{ $errors->has('phone') ? 'is-invalid' : '' }}"
                            name="phone" value="{{ old('phone') }}" placeholder="959811..." required>
                        <label class="ps-3">WhatsApp (area code + number)</label>
                        @if ($errors->has('phone'))
                        <div class="invalid-feedback ps-1">{{ $errors->first('phone') }}</div>
                        @endif
                    </div>
                </div>
                <div class="form-text text-white-50 ms-2" style="font-size: 0.7rem;">Example: 95981115965 (without country code)</div>
            </div>
            <div class="form-floating mb-3 text-start">
                <input type="text" class="form-control form-control-lg {{ $errors->has('username') ? 'is-invalid' : '' }}"
                    name="username" value="{{ old('username') }}" placeholder="Username" required>
                <label class="ps-3">Username (login)</label>
                @if ($errors->has('username'))
                <div class="invalid-feedback ps-1">{{ $errors->first('username') }}</div>
                @endif
            </div>

            <div class="form-floating mb-3 text-start">
                <input type="password" class="form-control form-control-lg {{ $errors->has('password') ? 'is-invalid' : '' }}"
                    name="password" placeholder="Password" required>
                <label class="ps-3">Password</label>
                @if ($errors->has('password'))
                <div class="invalid-feedback ps-1">{{ $errors->first('password') }}</div>
                @endif
            </div>

            <div class="form-floating mb-4 text-start">
                <input type="password" class="form-control form-control-lg"
                    name="password_confirmation" placeholder="Confirm Password" required>
                <label class="ps-3">Confirm password</label>
            </div>

            <button class="w-100 btn btn-lg btn-warning rounded-3 shadow py-3 fw-bold text-dark fs-5" type="submit">
                REGISTER AND REQUEST ACTIVATION
            </button>

            <div class="mt-4 text-white-50">
                Already have an account? <a href="{{ route('login.perform') }}" class="text-warning text-decoration-none fw-bold">Sign in</a>
            </div>

            <div class="mt-4 opacity-50">
                @include('auth.partials.copy')
            </div>
        </form>
    </div>
</div>

<style>
    body {
        background-color: #121212 !important;
        background: radial-gradient(circle at top right, #1e1e1e, #121212) !important;
        min-height: 100vh;
        display: flex;
        align-items: center;
    }

    .auth-card {
        background-color: #1e1e1e !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        /* max-width: 650px; */
        margin: 40px auto;
    }

    .form-control-lg {
        height: 65px !important;
        font-size: 1.1rem !important;
        background-color: #ffffff !important;
        border: 2px solid #ced4da !important;
        color: #212529 !important;
        border-radius: 12px !important;
    }

    .form-floating>label {
        padding-left: 1rem !important;
        color: #6c757d !important;
    }

    .form-floating>.form-control:focus~label,
    .form-floating>.form-control:not(:placeholder-shown)~label {
        color: #ffc107 !important;
        font-weight: bold;
        transform: scale(0.85) translateY(-0.75rem) translateX(0.15rem) !important;
    }

    .form-control:focus {
        border-color: #ffc107 !important;
        box-shadow: 0 0 0 0.3rem rgba(255, 193, 7, 0.2) !important;
    }

    .btn-warning {
        transition: all 0.3s ease;
        letter-spacing: 0.5px;
    }

    .btn-warning:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(255, 193, 7, 0.4);
    }
</style>
@endsection