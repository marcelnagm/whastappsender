@extends('layouts.app-master')

@section('content')
<div class="container py-4">
    {{-- Header Estratégico --}}
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-5 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1">
                <i class="bi bi-whatsapp text-success me-2"></i>Minhas Instâncias
            </h2>
            <p class="text-muted mb-0 small">Gerencie suas conexões e o aquecimento de chips (Warmup).</p>
        </div>
        <a href="{{ route('instances.create') }}" class="btn btn-primary px-4 shadow-sm fw-bold">
            <i class="bi bi-plus-lg me-2"></i>Nova Instância
        </a>
    </div>

    <div class="row g-4">
        @forelse($instances as $instance)
        <div class="col-xl-4 col-md-6">
            <div class="card border-0 shadow-sm h-100 position-relative overflow-hidden" style="border-radius: 12px; transition: transform 0.2s;">
                {{-- Indicador Lateral de Status --}}
                <div class="position-absolute start-0 top-0 bottom-0 {{ $instance->status === 'connected' ? 'bg-success' : 'bg-warning' }}" style="width: 5px;"></div>

                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div>
                            <h5 class="fw-bold mb-0 text-truncate" style="max-width: 160px;">{{ $instance->name }}</h5>
                            <small class="text-muted font-monospace d-block mt-1">
                                <i class="bi bi-hash me-1"></i>{{ $instance->instance_name }}
                            </small>
                        </div>
                        <div class="text-end">
                            <span class="badge rounded-pill {{ $instance->status === 'connected' ? 'bg-success-subtle text-success border border-success' : 'bg-warning-subtle text-dark border border-warning' }} px-2 py-1 mb-1 d-block">
                                {{ strtoupper($instance->status) }}
                            </span>
                            @if($instance->warmup)
                            <span class="badge bg-fire text-white border-0 px-2 py-1" style="font-size: 0.65rem;">
                                <i class="bi bi-fire me-1"></i>WARMUP ATIVO
                            </span>
                            @endif
                        </div>
                    </div>

                    <hr class="text-secondary opacity-25">

                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <a href="{{ route('whatsapp.index', $instance->id) }}" class="btn btn-outline-dark btn-sm w-100 py-2 fw-semibold">
                                <i class="bi bi-qr-code-scan d-block mb-1"></i> Conectar
                            </a>
                        </div>
                        <div class="col-6">
                            <a href="{{ route('instances.sync', $instance->id) }}" class="btn btn-light border btn-sm w-100 py-2 fw-semibold text-primary">
                                <i class="bi bi-arrow-repeat d-block mb-1"></i> Sincronizar
                            </a>
                        </div>
                        <div class="col-12 mt-2">
                            <a href="{{ route('instances.warmup', $instance->id) }}"
                                class="btn {{ $instance->warmup ? 'btn-danger' : 'btn-outline-danger' }} btn-sm w-100 py-2 fw-semibold">
                                <i class="bi bi-fire me-1"></i>
                                {{ $instance->warmup ? 'Desativar Aquecimento' : 'Ativar Aquecimento' }}
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-transparent border-0 p-3 pt-0 d-flex justify-content-between align-items-center">
                    <form action="{{ route('instances.destroy', $instance->id) }}" method="POST" onsubmit="return confirm('ATENÇÃO: Excluir esta instância removerá todos os dados vinculados. Confirmar?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-link text-danger btn-sm p-0 text-decoration-none opacity-75 hover-opacity-100">
                            <i class="bi bi-trash3 me-1"></i>Remover
                        </button>
                    </form>
                    <small class="text-muted" style="font-size: 0.75rem;">ID: #{{ $instance->id }}</small>
                </div>
            </div>
        </div>
        @empty
        {{-- Empty state mantido conforme original --}}
        @endforelse
    </div>
</div>

<style>
    .hover-opacity-100:hover {
        opacity: 1 !important;
    }

    .card:hover {
        transform: translateY(-5px);
    }

    .bg-success-subtle {
        background-color: #d1e7dd;
    }

    .bg-warning-subtle {
        background-color: #fff3cd;
    }

    /* Cor de fogo personalizada para o Warmup */
    .bg-fire {
        background: linear-gradient(45deg, #f093fb 0%, #f5576c 100%);
    }

    .btn-outline-danger:hover {
        color: white !important;
    }
</style>
@endsection