@extends('layouts.app-master')

@section('template_title')
    Editar Campanha: {{ $campaign->name }}
@endsection

@section('content')
    <div class="container-fluid">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Ajustar Campanha
                </h1>
                <p class="text-muted small mb-0">Você está editando a campanha: <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">{{ $campaign->name }}</span></p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('campaigns.index') }}" class="btn btn-outline-secondary btn-sm shadow-sm">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                
                @includeif('partials.errors')

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-0 py-3 border-bottom d-flex align-items-center justify-content-between">
                        <span class="fw-bold text-muted text-uppercase small">Configurações da Estratégia</span>
                        <span class="text-xs text-muted">ID: #{{ $campaign->id }}</span>
                    </div>
                    
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('campaigns.update', $campaign->id) }}" role="form" enctype="multipart/form-data">
                            {{ method_field('PATCH') }}
                            @csrf

                            @include('campaign.form')

                        </form>
                    </div>
                </div>

                <div class="card border-0 mt-4 rounded-4 shadow-sm bg-light">
                    <div class="card-body p-3">
                        <div class="row align-items-center">
                            <div class="col-auto">
                                <div class="icon-shape bg-white text-info rounded-circle shadow-sm p-2">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                            </div>
                            <div class="col">
                                <p class="text-muted small mb-0">
                                    <strong>Nota:</strong> Alterar o nome ou as configurações básicas não afeta as mensagens já disparadas, apenas os novos envios desta campanha.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

<style>
    .text-xs { font-size: 0.7rem; }
    .bg-primary-subtle { background-color: rgba(13, 110, 253, 0.1); }
</style>
@endsection