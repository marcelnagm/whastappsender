@extends('layouts.app-master')

@section('template_title', 'My profile & AI')

@section('content')
<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-10 col-lg-9">
            <div class="d-flex align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-person-circle text-primary me-2"></i>{{ __('My profile & AI settings') }}
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
                $isAiReady = (bool) $user->ai_enabled && $user->ai_mode === 'auto';
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
                    {{ __('The AI replies from the same instance that received the message in the webhook, without switching to another instance.') }}
                </small>
            </div>

            <form method="POST" action="{{ route('profile.update') }}">
                @csrf

                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white py-3 border-bottom">
                        <h6 class="m-0 font-weight-bold text-primary">{{ __('Account details') }}</h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label for="name" class="form-label small fw-bold text-uppercase">{{ __('Full name') }}</label>
                                <input type="text" name="name" id="name" class="form-control" value="{{ old('name', $user->name) }}" required>
                            </div>
                            <div class="col-md-6">
                                <label for="username" class="form-label small fw-bold text-uppercase">{{ __('Username') }}</label>
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
                                <label for="password" class="form-label small fw-bold text-uppercase">{{ __('New password') }}</label>
                                <input type="password" name="password" id="password" class="form-control" placeholder="{{ __('Leave blank to keep current') }}">
                            </div>
                            <div class="col-md-6">
                                <label for="password_confirmation" class="form-label small fw-bold text-uppercase">{{ __('Confirm password') }}</label>
                                <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" placeholder="{{ __('Repeat new password') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm rounded-4">
                    <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <h6 class="m-0 fw-bold text-primary">{{ __('AI agents') }}</h6>
                            <small class="text-muted">{{ __('Provider, model, prompt, instances and working hours are now configured in the AI section.') }}</small>
                        </div>
                        <a href="{{ route('ai.index') }}" class="btn btn-outline-primary"><i class="bi bi-robot me-1"></i>{{ __('Open AI settings') }}</a>
                    </div>
                </div>

                <div class="mt-4 d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-5 shadow-sm fw-bold">
                        <i class="bi bi-check2-circle me-2"></i>{{ __('Save settings') }}
                    </button>
                </div>
            </form>

            <div class="card border-0 shadow-sm rounded-4 mt-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">{{ __('API para agentes de IA') }}</h6>
                </div>
                <div class="card-body p-4">
                    @if (session('api_token'))
                        <div class="alert alert-success border-0 mb-4">
                            <strong>{{ __('Token gerado — copie agora:') }}</strong>
                            <code class="d-block mt-2 user-select-all">{{ session('api_token') }}</code>
                        </div>
                    @endif

                    <p class="text-muted small mb-3">
                        {{ __('Use o token Bearer para integrar agentes de IA via REST ou Tool Call Chain. Base URL:') }} <code>{{ url('/api/v1') }}</code>
                    </p>

                    <ul class="small text-muted mb-4">
                        <li><code>GET /api/v1/tools</code> {{ __('— lista de tools (formato OpenAI Functions)') }}</li>
                        <li><code>POST /api/v1/tools/call</code> {{ __('— executa uma tool') }}</li>
                        <li><code>POST /api/v1/tools/chain</code> {{ __('— executa cadeia de tools') }}</li>
                    </ul>

                    <form method="POST" action="{{ route('profile.api-tokens.create') }}" class="row g-2 align-items-end mb-4">
                        @csrf
                        <div class="col-md-8">
                            <label for="token_name" class="form-label small fw-bold text-uppercase">{{ __('Nome do token') }}</label>
                            <input type="text" name="token_name" id="token_name" class="form-control" placeholder="{{ __('ex: cursor-agent') }}" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-key me-1"></i>{{ __('Gerar token') }}
                            </button>
                        </div>
                    </form>

                    @if ($apiTokens->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('Nome') }}</th>
                                        <th>{{ __('Criado') }}</th>
                                        <th>{{ __('Último uso') }}</th>
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
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Revogar') }}</button>
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
