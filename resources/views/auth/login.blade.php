@extends('layouts.auth-master')

@section('content')
<div class="card shadow-lg border-0 rounded-lg">
    <div class="card-body p-5">
        <div class="text-center mb-4">
            {{-- Substitua por um ícone de "Radar" ou "Data" que remeta a mineração --}}
            <div class="bg-primary bg-gradient text-white d-inline-block p-3 rounded-circle mb-3 shadow">
                <i class="bi bi-radar h2"></i> 
            </div>
            <h2 class="fw-bold">Mining Engine</h2>
            <p class="text-muted">Acesse sua central de inteligência</p>
        </div>

        <form method="post" action="{{ route('login.perform') }}">
            <input type="hidden" name="_token" value="{{ csrf_token() }}" />

            @include('layouts.partials.messages')

            <div class="form-floating mb-3">
                <input type="text" class="form-control border-0 bg-light" id="floatingName" name="username" 
                       value="{{ old('username') }}" placeholder="Username" required autofocus>
                <label for="floatingName">Usuário ou E-mail</label>
                @if ($errors->has('username'))
                    <small class="text-danger">{{ $errors->first('username') }}</small>
                @endif
            </div>
            
            <div class="form-floating mb-3">
                <input type="password" class="form-control border-0 bg-light" id="floatingPassword" name="password" 
                       placeholder="Password" required>
                <label for="floatingPassword">Senha</label>
                @if ($errors->has('password'))
                    <small class="text-danger">{{ $errors->first('password') }}</small>
                @endif
            </div>

            <div class="d-flex align-items-center justify-content-between mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
                    <label class="form-check-label text-muted" for="remember">
                        Manter conectado
                    </label>
                </div>
                {{-- Link de esqueci a senha pode ser adicionado aqui depois --}}
            </div>

            <button class="btn btn-primary btn-lg w-100 shadow-sm fw-bold py-3" type="submit">
                Entrar no Sistema
            </button>
        </form>
    </div>
    <div class="card-footer bg-white border-0 text-center pb-4">
        @include('auth.partials.copy')
    </div>
</div>
@endsection