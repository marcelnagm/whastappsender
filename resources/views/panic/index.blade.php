@extends('layouts.app-master')

@section('template_title', 'Centro de Controle de Crise')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-0 text-danger fw-bold">
            <span class="bg-danger bg-opacity-10 p-2 rounded-3 me-2">
                <i class="bi bi-exclamation-octagon-fill text-danger"></i>
            </span>
            Centro de Controle de Crise
        </h1>
        <p class="text-muted">Interrupção imediata de disparos de campanha e processos de aquecimento (Warmup).</p>
    </div>

    @include('layouts.partials.messages')

    <div class="row g-4">
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5">
                    <h6 class="text-uppercase text-muted small fw-bold mb-3">Estado Geral</h6>
                    @if(Cache::get('system_panic_mode'))
                        <div class="display-4 text-danger mb-2"><i class="bi bi-pause-circle-fill"></i></div>
                        <span class="badge bg-danger px-3 py-2 rounded-pill">SISTEMA BLOQUEADO</span>
                    @else
                        <div class="display-4 text-success mb-2"><i class="bi bi-play-circle-fill"></i></div>
                        <span class="badge bg-success px-3 py-2 rounded-pill">OPERACIONAL</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px;">
                <div class="card-body d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-2">Kill Switch</h5>
                        <p class="small text-muted">Pausa o processamento de <strong>Campanhas</strong> e <strong>Warmup</strong>. Os itens permanecem na fila, mas não são enviados.</p>
                    </div>
                    <form action="{{ route('admin.panic.toggle') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn {{ Cache::get('system_panic_mode') ? 'btn-success' : 'btn-outline-danger' }} w-100 py-3 fw-bold rounded-pill">
                            <i class="bi {{ Cache::get('system_panic_mode') ? 'bi-play-fill' : 'bi-stop-fill' }} me-2"></i>
                            {{ Cache::get('system_panic_mode') ? 'RETOMAR TUDO' : 'PAUSAR TUDO' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 border-danger border-opacity-10" style="border-radius: 15px;">
                <div class="card-body d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold text-danger mb-2">Flush: Campanhas</h5>
                        <p class="small text-muted">Apaga permanentemente todos os disparos pendentes na fila <code>disparos</code>.</p>
                    </div>
                    <form action="{{ route('admin.panic.clear') }}" method="POST" onsubmit="return confirm('ATENÇÃO: Apagar fila de CAMPANHA?')">
                        @csrf
                        <button type="submit" class="btn btn-danger w-100 py-3 fw-bold rounded-pill shadow-sm">
                            <i class="bi bi-trash3-fill me-2"></i> LIMPAR CAMPANHAS
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 border-warning border-opacity-25" style="border-radius: 15px;">
                <div class="card-body d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold text-warning mb-2">Flush: Warmup</h5>
                        <p class="small text-muted">Apaga permanentemente todos os processos de aquecimento na fila <code>warmup</code>.</p>
                    </div>
                    <form action="{{ route('admin.panic.Warmup') }}" method="POST" onsubmit="return confirm('ATENÇÃO: Apagar fila de WARMUP?')">
                        @csrf
                        <button type="submit" class="btn btn-warning w-100 py-3 fw-bold text-white rounded-pill shadow-sm">
                            <i class="bi bi-fire me-2"></i> LIMPAR WARMUP
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection