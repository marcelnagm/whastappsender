@extends('layouts.app-master')

@section('template_title', 'My profile & AI')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-9">
            <div class="d-flex align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-person-circle text-primary me-2"></i>My profile & AI settings
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
                        {{ $isAiReady ? 'AI is ready for automatic replies.' : 'AI is not in automatic mode yet.' }}
                    </span>
                    <span class="badge {{ $isAiReady ? 'bg-success' : 'bg-secondary' }}">
                        {{ $isAiReady ? 'Status: Active' : 'Status: Inactive' }}
                    </span>
                </div>
                <small class="d-block mt-2">
                    The AI replies from the same instance that received the message in the webhook, without switching to another instance.
                </small>
            </div>

            <form method="POST" action="{{ route('profile.update') }}">
                @csrf

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary">Account details</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label small fw-bold text-uppercase">Full name</label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="username" class="form-label small fw-bold text-uppercase">Username</label>
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
                                <label for="password" class="form-label small fw-bold text-uppercase">New password</label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="Leave blank to keep current">
                            </div>
                            <div class="col-md-6">
                                <label for="password_confirmation" class="form-label small fw-bold text-uppercase">Confirm password</label>
                                <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" placeholder="Repeat new password">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary">AI assistant (GROQ)</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="ai_enabled" id="ai_enabled" value="1" {{ old('ai_enabled', $user->ai_enabled) ? 'checked' : '' }}>
                                    <label class="form-check-label fw-semibold" for="ai_enabled">Enable AI for my WhatsApp</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" role="switch" name="ai_business_hours_only" id="ai_business_hours_only" value="1" {{ old('ai_business_hours_only', $user->ai_business_hours_only) ? 'checked' : '' }}>
                                    <label class="form-check-label fw-semibold" for="ai_business_hours_only">Reply only during business hours</label>
                                </div>
                            </div>

                            <div class="col-md-4">
                                <label for="ai_mode" class="form-label small fw-bold text-uppercase">AI mode</label>
                                <select name="ai_mode" id="ai_mode" class="form-select" required>
                                    <option value="off" {{ old('ai_mode', $user->ai_mode) === 'off' ? 'selected' : '' }}>Off</option>
                                    <option value="assist" {{ old('ai_mode', $user->ai_mode) === 'assist' ? 'selected' : '' }}>Assist</option>
                                    <option value="auto" {{ old('ai_mode', $user->ai_mode) === 'auto' ? 'selected' : '' }}>Automatic</option>
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
                                <label for="ai_system_prompt" class="form-label small fw-bold text-uppercase">AI system prompt</label>
                                <textarea name="ai_system_prompt" id="ai_system_prompt" rows="8" class="form-control" placeholder="e.g. You are a sales assistant for company X; reply concisely and ask for full name before proceeding.">{{ old('ai_system_prompt', $user->ai_system_prompt) }}</textarea>
                                <small class="text-muted d-block mt-2">
                                    This prompt defines the agent's baseline behavior. In automatic mode it is used for GROQ-generated replies.
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-5 shadow-sm fw-bold">
                        <i class="bi bi-check2-circle me-2"></i>Save settings
                    </button>
                </div>
            </form>

            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">API para agentes de IA</h6>
                </div>
                <div class="card-body p-4">
                    @if (session('api_token'))
                        <div class="alert alert-success border-0 mb-4">
                            <strong>Token gerado — copie agora:</strong>
                            <code class="d-block mt-2 user-select-all">{{ session('api_token') }}</code>
                        </div>
                    @endif

                    <p class="text-muted small mb-3">
                        Use o token Bearer para integrar agentes de IA via REST ou Tool Call Chain.
                        Base URL: <code>{{ url('/api/v1') }}</code>
                    </p>

                    <ul class="small text-muted mb-4">
                        <li><code>GET /api/v1/tools</code> — lista de tools (formato OpenAI Functions)</li>
                        <li><code>POST /api/v1/tools/call</code> — executa uma tool</li>
                        <li><code>POST /api/v1/tools/chain</code> — executa cadeia de tools</li>
                    </ul>

                    <form method="POST" action="{{ route('profile.api-tokens.create') }}" class="row g-2 align-items-end mb-4">
                        @csrf
                        <div class="col-md-8">
                            <label for="token_name" class="form-label small fw-bold text-uppercase">Nome do token</label>
                            <input type="text" name="token_name" id="token_name" class="form-control" placeholder="ex: cursor-agent" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-key me-1"></i>Gerar token
                            </button>
                        </div>
                    </form>

                    @if ($apiTokens->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Criado</th>
                                        <th>Último uso</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($apiTokens as $token)
                                        <tr>
                                            <td>{{ $token->name }}</td>
                                            <td>{{ $token->created_at?->format('d/m/Y H:i') }}</td>
                                            <td>{{ $token->last_used_at?->format('d/m/Y H:i') ?? '—' }}</td>
                                            <td class="text-end">
                                                <form method="POST" action="{{ route('profile.api-tokens.revoke', $token->id) }}" class="d-inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Revogar</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
