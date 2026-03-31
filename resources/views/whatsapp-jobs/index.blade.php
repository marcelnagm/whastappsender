@extends('layouts.app-master')

@section('content')
@php
// Processamento de Stats para a UI
$processados = $statsStatus['processado'] ?? 0;
$erros = $statsStatus['erro'] ?? 0;
$pendentes = $statsStatus['pendente'] ?? 0;
$totalGeral = $processados + $erros + $pendentes;

$calcPercent = fn($parcial, $total) => $total > 0 ? round(($parcial / $total) * 100, 1) : 0;

$statusMap = [
'processado' => ['label' => 'Sucesso', 'class' => 'bg-success shadow-sm'],
'erro' => ['label' => 'Falha', 'class' => 'bg-danger shadow-sm'],
'pendente' => ['label' => 'Em Fila', 'class' => 'bg-warning text-dark shadow-sm'],
];

$statusMapEvolution = [
'sent' => 'Enviado',
'delivery_ack' => 'Entregue',
'read' => 'Lido',
'viewed' => 'Visualizado'
];
@endphp

<div class="container-fluid py-4">
    {{-- Header & Ações Rápida --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">
            <i class="bi bi-terminal-split text-primary me-2"></i>Monitor de Operações
        </h1>
        <div>
            <button class="btn btn-outline-primary btn-sm fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-funnel-fill me-1"></i> FILTROS
            </button>
            <a href="{{route('campaign-items.index')}}" class="btn btn-secondary btn-sm  fw-bold">VOLTAR A LISTA</a>
            <span class="badge bg-dark px-3 py-2 ms-2">ITEM #{{ $id }}</span>
        </div>
    </div>

    {{-- Formulário de Filtros (Fase 2: Inteligência) --}}
    <div class="collapse {{ request()->anyFilled(['contact', 'status', 'evolution_status']) ? 'show' : '' }} mb-4" id="filterCollapse">
        <div class="card border-0 shadow-sm card-body bg-light">
            <form method="GET" action="{{ route('whatsapp-jobs.index', $id) }}" class="row g-3">
                <div class="col-md-4">
                    <label class="x-small fw-bold text-uppercase text-muted">Buscar Contato/Payload</label>
                    <input type="text" name="contact" class="form-control form-control-sm" value="{{ request('contact') }}" placeholder="Nome, número ou termo...">
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-uppercase text-muted">Status Interno</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="processado" {{ request('status') == 'processado' ? 'selected' : '' }}>Sucesso</option>
                        <option value="erro" {{ request('status') == 'erro' ? 'selected' : '' }}>Falha</option>
                        <option value="pendente" {{ request('status') == 'pendente' ? 'selected' : '' }}>Em Fila</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-uppercase text-muted">Status WhatsApp</label>
                    <select name="evolution_status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach($statusMapEvolution as $key => $label)
                        <option value="{{ $key }}" {{ request('evolution_status') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">FILTRAR</button>
                    <a href="{{ route('whatsapp-jobs.index', $id) }}" class="btn btn-outline-secondary btn-sm w-100 fw-bold">LIMPAR</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Dashboard de Performance --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-3 border-end">
                    <label class="text-muted x-small fw-bold text-uppercase d-block mb-1">Eficiência do Filtro</label>
                    <h2 class="fw-bold mb-0 text-primary">{{ $calcPercent($processados, $totalGeral) }}%</h2>
                </div>
                <div class="col-md-9 ps-md-4">
                    <div class="d-flex justify-content-between mb-2 small fw-bold">
                        <span class="text-success">Sucesso: {{ $processados }}</span>
                        <span class="text-danger">Falhas: {{ $erros }}</span>
                        <span class="text-warning">Fila: {{ $pendentes }}</span>
                    </div>
                    <div class="progress shadow-sm" style="height: 12px; border-radius: 10px; background-color: #f0f2f8;">
                        <div class="progress-bar bg-success" style="width: {{ $calcPercent($processados, $totalGeral) }}%"></div>
                        <div class="progress-bar bg-danger" style="width: {{ $calcPercent($erros, $totalGeral) }}%"></div>
                        <div class="progress-bar bg-warning" style="width: {{ $calcPercent($pendentes, $totalGeral) }}%"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Tabela Principal --}}
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="jobsTable">
                <thead class="bg-light x-small text-uppercase fw-bold text-muted border-bottom">
                    <tr>
                        <th class="ps-4">Destinatário</th>
                        <th>Status Interno</th>
                        <th>Status WhatsApp</th>
                        <th>Ações</th>
                        @if(Auth::user()->role === 'admin')
                        <th class="text-center">Log Erro</th>
                        <th class="text-center">DevTools</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                    @php
                    $item = $job->campaignItem;
                    $payloadJson = $item ? json_encode($item->generate($job->contact_id)) : '{}';
                    $curlCommand = "curl -X POST '{$job->endpoint}' -H 'Content-Type: application/json' -d '" . addslashes($payloadJson) . "'";
                    @endphp
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold text-dark">{{ $job->contact->name ?? 'N/A' }}</div>
                            <div class="x-small text-muted">{{ $job->contact->contact ?? '---' }}</div>
                        </td>
                        <td>
                            <span class="badge {{ $statusMap[$job->status]['class'] ?? 'bg-secondary' }} rounded-pill px-3">
                                {{ $statusMap[$job->status]['label'] ?? $job->status }}
                            </span>
                        </td>
                        <td class="small fw-bold">
                            @if($job->evolution_status)
                            <span class="text-primary text-uppercase" style="font-size: 0.7rem;">
                                <i class="bi bi-whatsapp me-1"></i>{{ $statusMapEvolution[$job->evolution_status] ?? $job->evolution_status }}
                            </span>
                            @else
                            <span class="text-muted opacity-50 italic small">Processando...</span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-xs btn-outline-secondary"
                                    onclick="window.copyToClipboard(this.getAttribute('data-content'), '📂 JSON copiado!')"
                                    data-content='{!! $payloadJson !!}' title="Copiar Payload">
                                    <i class="bi bi-filetype-json"></i>
                                </button>

                                @if($job->status == 'erro')
                                <form action="{{ route('whatsapp-jobs.retry', $job->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-outline-danger" title="Retentar">
                                        <i class="bi bi-arrow-repeat"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>

                        @if(Auth::user()->role === 'admin')
                        <td class="text-center">
                            @if($job->erro_mensagem)
                            <button type="button" class="btn btn-xs btn-danger"
                                onclick="window.copyToClipboard(this.getAttribute('data-content'), '❌ Erro copiado!')"
                                data-content="{{ $job->erro_mensagem }}">
                                <i class="bi bi-bug-fill me-1"></i> Log
                            </button>
                            @else
                            <span class="text-muted small">---</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-dark fw-mono"
                                onclick="window.copyToClipboard(this.getAttribute('data-content'), '🚀 cURL copiado!')"
                                data-content="{{ $curlCommand }}">
                                <i class="bi bi-terminal-fill me-1"></i> cURL
                            </button>
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr>
                        <td colspan="100%" class="text-center py-5 text-muted italic">Nenhum registro encontrado para estes filtros.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{-- Paginação Crucial para Performance --}}
        @if($jobs->hasPages())
        <div class="card-footer bg-white py-3 border-top-0">
            {!! $jobs->links() !!}
        </div>
        @endif
    </div>
</div>

<style>
    .x-small {
        font-size: 0.72rem;
    }

    .btn-xs {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
    }

    .fw-mono {
        font-family: monospace;
        font-size: 0.7rem;
    }

    .badge {
        font-size: 0.65rem;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
    }
</style>

{{-- JS BLINDADO --}}
<script>
    window.copyToClipboard = function(text, successMsg) {
        if (!text || text === '{}') return;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(() => alert(successMsg)).catch(err => fallbackCopy(text, successMsg));
        } else {
            fallbackCopy(text, successMsg);
        }
    };

    function fallbackCopy(text, successMsg) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-9999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            if (document.execCommand('copy')) alert(successMsg);
        } catch (err) {
            console.error('Falha ao copiar:', err);
        }
        document.body.removeChild(textArea);
    }
</script>
@endsection