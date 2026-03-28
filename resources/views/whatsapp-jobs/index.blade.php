@extends('layouts.app-master')

@section('content')
@php
    $statusInterno = $statsStatus->toArray();
    $processados = $statusInterno['processado'] ?? 0;
    $erros = $statusInterno['erros'] ?? 0;
    $pendentes = $statusInterno['pendente'] ?? 0;
    $totalGeral = $processados + $erros + $pendentes;

    $calcPercent = fn($parcial, $total) => $total > 0 ? round(($parcial / $total) * 100, 1) : 0;
    
    // Mapeamento de Status para UX
    $statusMap = [
        'processado' => ['label' => 'Sucesso', 'class' => 'bg-success'],
        'erro'       => ['label' => 'Falha', 'class' => 'bg-danger'],
        'pendente'   => ['label' => 'Em Fila', 'class' => 'bg-warning text-dark'],
    ];
@endphp

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">
            <i class="bi bi-terminal-split text-primary me-2"></i>Monitor de Operações
        </h1>
        <div class="text-end">
            <span class="badge bg-dark">Lote ID: {{ $id }}</span>
            <div class="x-small text-muted mt-1 fw-bold text-uppercase">Volumetria Total: {{ $totalGeral }}</div>
        </div>
    </div>

    <div class="row mb-4 g-3">
        <div class="col-md-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body p-4">
                    <div class="row align-items-center">
                        <div class="col-md-3 border-end">
                            <label class="text-muted x-small fw-bold text-uppercase d-block mb-2">Eficiência Interna</label>
                            <h2 class="fw-bold mb-0">{{ $calcPercent($processados, $totalGeral) }}%</h2>
                            <p class="text-muted small mb-0">Taxa de processamento local</p>
                        </div>
                        <div class="col-md-9 ps-md-4">
                            <div class="d-flex justify-content-between mb-2">
                                <span class="small fw-bold text-success">Sucesso: {{ $processados }}</span>
                                <span class="small fw-bold text-danger">Falhas: {{ $erros }}</span>
                                <span class="small fw-bold text-warning">Fila: {{ $pendentes }}</span>
                            </div>
                            <div class="progress" style="height: 12px;">
                                <div class="progress-bar bg-success" style="width: {{ $calcPercent($processados, $totalGeral) }}%"></div>
                                <div class="progress-bar bg-danger" style="width: {{ $calcPercent($erros, $totalGeral) }}%"></div>
                                <div class="progress-bar bg-warning" style="width: {{ $calcPercent($pendentes, $totalGeral) }}%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light x-small text-uppercase fw-bold">
                    <tr>
                        <th class="ps-4">Destinatário</th>
                        <th>Status Interno</th>
                        <th>Retorno Evolution</th>
                        <th>Ações</th>
                        @if(auth()->user()->is_admin)
                            <th class="text-danger">Log de Erro</th>
                            <th class="text-center">DevTools</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                    @php
                        // Geração de Payload em Tempo de Visualização
                        // Assume que você tem o contato e o item da campanha carregados ou acessíveis
                        $dynamicPayload = $job->campaignItem->generate($job->contact_id);
                    @endphp
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark">{{ $job->contact->name ?? 'N/A' }}</div>
                            <div class="x-small text-muted">{{ $job->contact->contact ?? 'Sem número' }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $statusMap[$job->status]['class'] ?? 'bg-secondary' }} rounded-pill px-3">
                                {{ $statusMap[$job->status]['label'] ?? $job->status }}
                            </span>
                        </td>
                        <td class="small fw-bold">
                            @if($job->evolution_status)
                                <span class="text-primary"><i class="bi bi-whatsapp me-1"></i>{{ $job->evolution_status }}</span>
                            @else
                                <span class="text-muted italic">Aguardando...</span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-xs btn-outline-secondary js-copy" 
                                        data-value="{{ json_encode($job->generate($job->contact_id)) }}" title="Ver JSON Dinâmico">
                                    <i class="bi bi-code-slash"></i>
                                </button>
                                
                                @if($job->status == 'erro')
                                <form action="{{ route('whatsapp-jobs.retry', $job->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-outline-danger" title="Reprocessar Agora">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                        
                        {{-- ÁREA RESTRITA: ADMIN --}}
                        @if(auth()->user()->is_admin)
                        <td class="small text-danger">
                            <span title="{{ $job->erro_mensagem }}" style="cursor:help">
                                {{ Str::limit($job->erro_mensagem, 40) ?: '---' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-dark js-curl"
                                    data-endpoint="{{ $job->endpoint }}"
                                    data-payload="{{ json_encode($dynamicPayload) }}">
                                <i class="bi bi-terminal"></i> cURL
                            </button>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-5">
                            <p class="text-muted mb-0">Nenhum registro encontrado para este filtro.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($jobs->hasPages())
        <div class="card-footer bg-white py-3">
            {{ $jobs->appends(request()->query())->links() }}
        </div>
        @endif
    </div>
</div>

<style>
    .x-small { font-size: 0.72rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.75rem; }
    .progress { background-color: #eaecf4; }
    .table thead th { border-top: none; }
    /* Estilo para paginação Laravel */
    nav[role="navigation"] svg { width: 15px; }
</style>
@endsection