@extends('layouts.app-master')

@section('content')
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark"><i class="bi bi-whatsapp text-success me-2"></i>Minhas Instâncias</h2>
        <a href="{{ route('instances.create') }}" class="btn btn-primary fw-bold">
            <i class="bi bi-plus-lg"></i> Nova Instância
        </a>
    </div>

    <div class="row">
        @foreach($instances as $instance)
        <div class="col-md-4 mb-3">
            <div class="card bg-white border shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h5 class="card-title text-primary fw-bold mb-0">{{ $instance->name }}</h5>
                        <span class="badge {{ $instance->status === 'connected' ? 'bg-success' : 'bg-warning text-dark' }}">
                            {{ strtoupper($instance->status) }}
                        </span>
                    </div>
                    <p class="text-muted small mb-3">
                        <i class="bi bi-telephone me-1"></i> {{ $instance->instance_name }}
                    </p>
                    
                    <div class="d-grid gap-2">
                        <a href="{{ route('whatsapp.index', $instance->id) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-qr-code-scan me-1"></i> Conectar WhatsApp
                        </a>
                        <form action="{{ route('instances.destroy', $instance->id) }}" method="POST" onsubmit="return confirm('Excluir esta instância?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-link text-danger btn-sm w-100 text-decoration-none p-0">
                                <i class="bi bi-trash"></i> Remover
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endsection