@extends('layouts.app-master')

@section('template_title', 'Meu Perfil e IA')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-9">
            <div class="d-flex align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-person-circle text-primary me-2"></i>Meu Perfil e Configuração da IA
                </h1>
            </div>

            @if ($errors->any())
                <div class="alert alert-danger border-0 mb-4">
                    <ul class="mb-0">
                        @foreach ($errors->all() as $error)
                            <li><i class="bi bi-exclamation-triangle-fill me-2"></i>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $isAiReady = (bool) old('ai_enabled', $user->ai_enabled) && old('ai_mode', $user->ai_mode) === 'auto';
            @endphp

            <div class="alert {{ $isAiReady ? 'alert-success' : 'alert-warning' }} border-0 mb-4">
                <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                    <span>
                        <i class="bi {{ $isAiReady ? 'bi-check-circle-fill' : 'bi-exclamation-triangle-fill' }} me-2"></i>
                        {{ $isAiReady ? 'IA pronta para resposta automatica.' : 'IA ainda nao esta em modo automatico.' }}
                    </span>
                    <span class="badge {{ $isAiReady ? 'bg-success' : 'bg-secondary' }}">
                        {{ $isAiReady ? 'Status: Ativo' : 'Status: Inativo' }}
                    </span>
                </div>
                <small class="d-block mt-2">
                    A IA responde pela mesma instancia que recebeu a mensagem no webhook, sem trocar para outra instancia.
                </small>
            </div>

            <form method="POST" action="{{ route('profile.update') }}">
                @csrf

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary">Dados da Conta</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label small fw-bold text-uppercase">Nome Completo</label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="username" class="form-label small fw-bold text-uppercase">Usuário</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light">@</span>
                                    <input type="text" name="username" id="username" class="form-control" value="{{ old('username', $user->username) }}" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label small fw-bold text-uppercase">E-mail</label>
                                <input type="email" name="email" id="email" class="form-control" value="{{ old('email', $user->email) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="password" class="form-label small fw-bold text-uppercase">Nova Senha</label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Deixe em branco para manter">
                            </div>
                            <div class="col-md-6">
                                <label for="password_confirmation" class="form-label small fw-bold text-uppercase">Confirmar Senha</label>
                                <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" placeholder="Repita a nova senha">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary">Funcionário de IA (GROQ)</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="ai_enabled" id="ai_enabled" value="1" {{ old('ai_enabled', $user->ai_enabled) ? 'checked' : '' }}>
                                    <label class="form-check-label fw-semibold" for="ai_enabled">Ativar IA para meu WhatsApp</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="ai_business_hours_only" id="ai_business_hours_only" value="1" {{ old('ai_business_hours_only', $user->ai_business_hours_only) ? 'checked' : '' }}>
                                    <label class="form-check-label fw-semibold" for="ai_business_hours_only">Responder apenas em horário comercial</label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label for="ai_mode" class="form-label small fw-bold text-uppercase">Modo da IA</label>
                                <select name="ai_mode" id="ai_mode" class="form-select" required>
                                    <option value="off" {{ old('ai_mode', $user->ai_mode) === 'off' ? 'selected' : '' }}>Desligado</option>
                                    <option value="assist" {{ old('ai_mode', $user->ai_mode) === 'assist' ? 'selected' : '' }}>Assistido</option>
                                    <option value="auto" {{ old('ai_mode', $user->ai_mode) === 'auto' ? 'selected' : '' }}>Automático</option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label for="ai_model" class="form-label small fw-bold text-uppercase">Modelo</label>
                                <input type="text" name="ai_model" id="ai_model" class="form-control" value="{{ old('ai_model', $user->ai_model ?: 'llama-3.3-70b-versatile') }}">
                            </div>

                            <div class="col-md-2">
                                <label for="ai_temperature" class="form-label small fw-bold text-uppercase">Temperature</label>
                                <input type="number" name="ai_temperature" id="ai_temperature" class="form-control" min="0" max="2" step="0.01" value="{{ old('ai_temperature', $user->ai_temperature ?? 0.70) }}">
                            </div>

                            <div class="col-md-2">
                                <label for="ai_max_tokens" class="form-label small fw-bold text-uppercase">Max Tokens</label>
                                <input type="number" name="ai_max_tokens" id="ai_max_tokens" class="form-control" min="50" max="8000" step="1" value="{{ old('ai_max_tokens', $user->ai_max_tokens ?? 1024) }}">
                            </div>

                            <div class="col-12">
                                <label for="ai_system_prompt" class="form-label small fw-bold text-uppercase">Prompt do Funcionário de IA</label>
                                <textarea name="ai_system_prompt" id="ai_system_prompt" rows="8" class="form-control" placeholder="Ex: Você é atendente comercial da empresa X, responda com objetividade e peça nome completo antes de avançar.">{{ old('ai_system_prompt', $user->ai_system_prompt) }}</textarea>
                                <small class="text-muted d-block mt-2">
                                    Este prompt define o comportamento base do agente. Em modo automático, ele será usado nas respostas geradas pelo GROQ.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-5 shadow-sm fw-bold">
                        <i class="bi bi-check2-circle me-2"></i>Salvar Configurações
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
