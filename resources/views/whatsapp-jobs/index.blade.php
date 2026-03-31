@extends('layouts.app-master')

@section('content')
@php
    // Dashboard Stats
    $processados = $statsStatus['processado'] ?? 0;
    $erros = $statsStatus['erro'] ?? 0;
    $pendentes = $statsStatus['pendente'] ?? 0;
    $totalGeral = $processados + $erros + $pendentes;

    $calcPercent = fn($parcial, $total) => $total > 0 ? round(($parcial / $total) * 100, 1) : 0;

    $statusMap = [
        'processado' => ['label' => 'Sucesso', 'class' => 'bg-success shadow-sm'],
        'erro'       => ['label' => 'Falha', 'class' => 'bg-danger shadow-sm'],
        'pendente'   => ['label' => 'Em Fila', 'class' => 'bg-warning text-dark shadow-sm'],
    ];

    $statusMapEvolution = [
        'sent'         => 'Enviado',
        'delivery_ack' => 'Entregue',
        'read'         => 'Lido',
        'viewed'       => 'Visualizado'
    ];
@endphp

<div class="container-fluid py-4">
    {{-- BARRA DE AÇÕES EM MASSA (STICKY BULK BAR) --}}
    <div id="bulkActionsBar" class="card border-0 shadow-lg bg-dark text-white position-fixed bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1050; min-width: 450px; border-radius: 50px;">
        <div class="card-body d-flex align-items-center justify-content-between py-2 px-4">
            <div class="small">
                <span id="selectedCount" class="fw-bold text-warning">0</span> selecionados
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-warning btn-sm fw-bold rounded-pill" onclick="submitBulk('retry')">
                    <i class="bi bi-arrow-repeat me-1"></i> RETENTAR
                </button>
                <button type="button" class="btn btn-danger btn-sm fw-bold rounded-pill" onclick="submitBulk('delete')">
                    <i class="bi bi-trash me-1"></i> REMOVER
                </button>
                <button type="button" class="btn btn-link btn-sm text-white text-decoration-none" onclick="toggleSelectAll(false)">
                    Cancelar
                </button>
            </div>
        </div>
    </div>

    {{-- Formulário Oculto para Bulk Actions --}}
    <form id="bulkActionForm" method="POST" action="" class="d-none">
        @csrf
        <input type="hidden" name="ids" id="bulkIdsInput">
        <input type="hidden" name="action" id="bulkActionInput">
    </form>

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800 fw-bold">
                <i class="bi bi-terminal-split text-primary me-2"></i>Monitor de Operações
            </h1>
            <p class="text-muted small mb-0">Gerenciamento de disparos e integridade de contatos.</p>
        </div>
        <div>
            <button class="btn btn-outline-primary btn-sm fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-funnel-fill me-1"></i> FILTROS
            </button>
            <a href="{{ route('campaign-items.index') }}" class="btn btn-secondary btn-sm fw-bold ms-2 shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> VOLTAR
            </a>
            <span class="badge bg-dark px-3 py-2 ms-2 shadow-sm">LOTE #{{ $id }}</span>
        </div>
    </div>

    {{-- Seção de Filtros --}}
    <div class="collapse {{ request()->anyFilled(['contact', 'status', 'evolution_status']) ? 'show' : '' }} mb-4" id="filterCollapse">
        <div class="card border-0 shadow-sm card-body bg-white border-start border-primary border-4">
            <form method="GET" action="{{ route('whatsapp-jobs.index', $id) }}" class="row g-3">
                <div class="col-md-4">
                    <label class="x-small fw-bold text-uppercase text-muted mb-1">Busca por Contato</label>
                    <input type="text" name="contact" class="form-control form-control-sm" value="{{ request('contact') }}" placeholder="Nome ou número...">
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-uppercase text-muted mb-1">Status Interno</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="processado" {{ request('status') == 'processado' ? 'selected' : '' }}>Sucesso</option>
                        <option value="erro" {{ request('status') == 'erro' ? 'selected' : '' }}>Falha</option>
                        <option value="pendente" {{ request('status') == 'pendente' ? 'selected' : '' }}>Em Fila</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-uppercase text-muted mb-1">Status WhatsApp</label>
                    <select name="evolution_status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        @foreach($statusMapEvolution as $key => $label)
                            <option value="{{ $key }}" {{ request('evolution_status') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm">FILTRAR</button>
                    <a href="{{ route('whatsapp-jobs.index', $id) }}" class="btn btn-light btn-sm w-100 fw-bold border">LIMPAR</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Dashboard de Performance --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <div class="row align-items-center">
                <div class="col-md-3 border-end text-center">
                    <label class="text-muted x-small fw-bold text-uppercase d-block mb-1">Taxa de Sucesso</label>
                    <h2 class="fw-bold mb-0 text-primary">{{ $calcPercent($processados, $totalGeral) }}%</h2>
                </div>
                <div class="col-md-9 ps-md-4">
                    <div class="d-flex justify-content-between mb-2 small fw-bold">
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>{{ $processados }} Sucesso</span>
                        <span class="text-danger"><i class="bi bi-x-circle me-1"></i>{{ $erros }} Falhas</span>
                        <span class="text-warning"><i class="bi bi-clock-history me-1"></i>{{ $pendentes }} Em Fila</span>
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
                        <th class="ps-4" style="width: 40px;">
                            <input type="checkbox" class="form-check-input shadow-none" id="selectAll" onclick="toggleSelectAll(this.checked)">
                        </th>
                        <th>Destinatário</th>
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
                        $contact = $job->contact;
                        $payloadJson = $item ? json_encode($item->generate($job->contact_id)) : '{}';
                        $curlCommand = "curl -X POST '{$job->endpoint}' -H 'Content-Type: application/json' -d '" . addslashes($payloadJson) . "'";
                        
                        // Lógica de Cores do Contato (Baseado em Ativo vs No-Whatsapp)
                        $isAtivo = ($contact && $contact->status === 'ativo');
                        $contactClass = $isAtivo ? 'text-success' : 'text-danger';
                        $contactIcon = $isAtivo ? 'bi-circle-fill' : 'bi-exclamation-triangle-fill';
                    @endphp
                    <tr id="row-{{ $job->id }}">
                        <td class="ps-4">
                            <input type="checkbox" class="form-check-input job-checkbox shadow-none" value="{{ $job->id }}" onclick="updateBulkBar()">
                        </td>
                        <td>
                            <div class="d-flex align-items-center">
                                <i class="bi {{ $contactIcon }} {{ $contactClass }} me-2" style="font-size: 0.6rem;" title="{{ $isAtivo ? 'Contato Ativo' : 'Número Inválido/Inativo' }}"></i>
                                <div>
                                    <div class="fw-bold text-dark">{{ $contact->name ?? 'N/A' }}</div>
                                    <div class="x-small {{ !$isAtivo ? 'text-danger fw-bold' : 'text-muted' }}">
                                        {{ $contact->contact ?? '---' }}
                                        @if(!$isAtivo && $contact->status === 'no-whatsapp')
                                            <span class="ms-1 border-start ps-1 text-uppercase" style="font-size: 0.6rem;">[No-WA]</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
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
                                <span class="text-muted opacity-50 italic small">Aguardando...</span>
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
                                    <button type="submit" class="btn btn-xs btn-outline-danger shadow-none" title="Retentar">
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
                                <span class="text-muted small opacity-50">---</span>
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
                        <td colspan="100%" class="text-center py-5 text-muted border-0">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Nenhum registro encontrado para os filtros aplicados.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        
        @if($jobs->hasPages())
        <div class="card-footer bg-white py-3 border-top-0 d-flex justify-content-center">
            {!! $jobs->links() !!}
        </div>
        @endif
    </div>
</div>

<style>
    .x-small { font-size: 0.72rem; }
    .btn-xs { padding: 0.25rem 0.5rem; font-size: 0.7rem; }
    .fw-mono { font-family: 'Courier New', Courier, monospace; font-size: 0.7rem; }
    .badge { font-size: 0.65rem; }
    .form-control-sm, .form-select-sm { font-size: 0.8rem; border-radius: 6px; }
    .table-hover tbody tr:hover { background-color: #f8f9fc; }
    .pagination { margin-bottom: 0; }
</style>

<script>
    /** --- LÓGICA DE SELEÇÃO EM MASSA --- **/
    function toggleSelectAll(checked) {
        document.querySelectorAll('.job-checkbox').forEach(cb => cb.checked = checked);
        document.getElementById('selectAll').checked = checked;
        updateBulkBar();
    }

    function updateBulkBar() {
        const selected = document.querySelectorAll('.job-checkbox:checked');
        const bar = document.getElementById('bulkActionsBar');
        const countSpan = document.getElementById('selectedCount');
        
        if (selected.length > 0) {
            bar.classList.remove('d-none');
            countSpan.innerText = selected.length;
        } else {
            bar.classList.add('d-none');
        }
    }

    function submitBulk(action) {
        const ids = Array.from(document.querySelectorAll('.job-checkbox:checked')).map(cb => cb.value);
        if (ids.length === 0) return;

        const confirmMsg = action === 'delete' 
            ? `Deseja realmente REMOVER ${ids.length} registros?` 
            : `Deseja REENVIAR ${ids.length} mensagens para a fila?`;

        if (!confirm(confirmMsg)) return;

        const route = action === 'delete' 
            ? "{{ route('whatsapp-jobs.bulk-delete') }}" 
            : "{{ route('whatsapp-jobs.bulk-retry') }}";
        
        document.getElementById('bulkActionForm').action = route;
        document.getElementById('bulkIdsInput').value = JSON.stringify(ids);
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkActionForm').submit();
    }

    /** --- LÓGICA DE CÓPIA PARA CLIPBOARD --- **/
    window.copyToClipboard = function(text, successMsg) {
        if (!text || text === '{}') return;
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text)
                .then(() => alert(successMsg))
                .catch(err => fallbackCopy(text, successMsg));
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
        try { if (document.execCommand('copy')) alert(successMsg); } catch (err) { console.error(err); }
        document.body.removeChild(textArea);
    }
</script>
@endsection