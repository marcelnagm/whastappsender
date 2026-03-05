@extends('layouts.auth-master')

@section('content')
<div class="card shadow-lg border-0 rounded-4 overflow-hidden">
    <div class="card-body p-4 p-md-5">
        <form method="post" action="{{ route('register.perform') }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}" />
            
            <img class="mb-4" src="{!! url('images/bootstrap-logo.svg') !!}" alt="Logo" width="72" height="57">
            
            <h1 class="h3 mb-1 fw-bold text-dark">Registrar</h1>
            <p class="text-muted small mb-4">Crie sua conta para começar</p>

            <div class="form-floating mb-2">
                <input type="email" class="form-control {{ $errors->has('email') ? 'is-invalid' : '' }}" 
                       name="email" value="{{ old('email') }}" placeholder="name@example.com" required autofocus>
                <label>E-mail</label>
            </div>
            @if ($errors->has('email'))
                <div class="text-danger text-start small mb-2 ps-1">{{ $errors->first('email') }}</div>
            @endif

            <div class="form-floating mb-2">
                <input type="text" class="form-control {{ $errors->has('username') ? 'is-invalid' : '' }}" 
                       name="username" value="{{ old('username') }}" placeholder="Username" required>
                <label>Usuário</label>
            </div>
            @if ($errors->has('username'))
                <div class="text-danger text-start small mb-2 ps-1">{{ $errors->first('username') }}</div>
            @endif

            <div class="form-floating mb-2">
                <input type="text" class="form-control {{ $errors->has('phone') ? 'is-invalid' : '' }}" 
                       name="phone" value="{{ old('phone') }}" placeholder="Phone" required>
                <label>Telefone (WhatsApp)</label>
            </div>
            @if ($errors->has('phone'))
                <div class="text-danger text-start small mb-2 ps-1">{{ $errors->first('phone') }}</div>
            @endif
            
            <div class="form-floating mb-2">
                <input type="password" class="form-control {{ $errors->has('password') ? 'is-invalid' : '' }}" 
                       name="password" placeholder="Password" required>
                <label>Senha</label>
            </div>
            @if ($errors->has('password'))
                <div class="text-danger text-start small mb-2 ps-1">{{ $errors->first('password') }}</div>
            @endif

            <div class="form-floating mb-4">
                <input type="password" class="form-control" 
                       name="password_confirmation" placeholder="Confirm Password" required>
                <label>Confirmar Senha</label>
            </div>

            <button class="w-100 btn btn-lg btn-primary rounded-3 shadow py-3 fw-bold" type="submit">
                CADASTRAR
            </button>
            
            <div class="mt-4">
                @include('auth.partials.copy')
            </div>
        </form>
    </div>
</div>

<style>
    /* Sobrescrevendo o limite do signin.css para o Registro ser mais largo e elegante */
    .form-signin {
        max-width: 450px !important; /* Aumenta a largura para não esmagar os campos */
        padding: 15px;
    }
    
    .card {
        background-color: #ffffff;
    }

    .form-control {
        border: 1px solid #dee2e6;
    }

    .form-control:focus {
        border-color: #0d6efd;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
    }

    .form-floating > label {
        color: #6c757d;
    }

    /* Ajuste para erros não deslocarem o layout bruscamente */
    .text-danger.small {
        font-size: 0.75rem;
    }
</style>
@endsection