@extends('layouts.app-master')

@section('content')
@php
// Status labels (internal keys unchanged)
$statusMapEvolution = [
'SENT' => 'Sent',
'SERVER_ACK' => 'Sent (server)',
'DELIVERY_ACK' => 'Delivered',
'READ' => 'Read',
'VIEWED' => 'Viewed'
];

$statusMap = [
'processado' => ['label' => 'Success', 'class' => 'bg-success shadow-sm'],
'erro' => ['label' => 'Failed', 'class' => 'bg-danger shadow-sm'],
'pendente' => ['label' => 'Queued', 'class' => 'bg-warning text-dark shadow-sm'],
];

// Dashboard totals
$errorsCount = $statsStatus['erro'] ?? 0;
$sentCount = ($statsEvolution['SERVER_ACK'] ?? 0) + ($statsEvolution['SENT'] ?? 0);
$deliveredCount = $statsEvolution['DELIVERY_ACK'] ?? 0;
$readCount = ($statsEvolution['READ'] ?? 0) + ($statsEvolution['VIEWED'] ?? 0);

$totalCalculado = $errorsCount + $sentCount + $deliveredCount + $readCount;
$totalParaDivisao = $totalCalculado ?: 1;

$calcPercent = fn($parcial) => round(($parcial / $totalParaDivisao) * 100, 1);

// Card layout for dashboard
$cards = [
['label' => 'Errors', 'color' => 'danger', 'count' => $errorsCount, 'pct' => $calcPercent($errorsCount), 'icon' => 'bi-x-circle'],
['label' => 'Sent', 'color' => 'primary', 'count' => $sentCount, 'pct' => $calcPercent($sentCount), 'icon' => 'bi-send'],
['label' => 'Delivered', 'color' => 'info', 'count' => $deliveredCount, 'pct' => $calcPercent($deliveredCount), 'icon' => 'bi-check2-all'],
['label' => 'Read', 'color' => 'success', 'count' => $readCount, 'pct' => $calcPercent($readCount), 'icon' => 'bi-eye']
];
@endphp

<div class="container-fluid py-4">
    {{-- Sticky bulk actions bar --}}
    <div id="bulkActionsBar" class="card border-0 shadow-lg bg-dark text-white position-fixed bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1050; min-width: 450px; border-radius: 50px;">
        <div class="card-body d-flex align-items-center justify-content-between py-2 px-4">
            <div class="small">
                <span id="selectedCount" class="fw-bold text-warning">0</span> selected
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-warning btn-sm fw-bold rounded-pill" onclick="submitBulk('retry')">
                    <i class="bi bi-arrow-repeat me-1"></i> RETRY
                </button>
                <button type="button" class="btn btn-danger btn-sm fw-bold rounded-pill" onclick="submitBulk('delete')">
                    <i class="bi bi-trash me-1"></i> REMOVE
                </button>
                <button type="button" class="btn btn-link btn-sm text-white text-decoration-none" onclick="toggleSelectAll(false)">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    {{-- Hidden form for bulk actions --}}
    <form id="bulkActionForm" method="POST" action="" class="d-none">
        @csrf
        <input type="hidden" name="ids" id="bulkIdsInput">
        <input type="hidden" name="action" id="bulkActionInput">
    </form>

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800 fw-bold">
                <i class="bi bi-terminal-split text-primary me-2"></i>Operations monitor
            </h1>
            <p class="text-muted small mb-0">Send jobs and contact delivery health.</p>
        </div>
        <div>
            <a href="{{ route('campaign-items.index') }}" class="btn btn-sm btn-secondary  fw-bold ms-2 shadow-sm">
                <i class="bi bi-arrow-left me-1"></i> BACK
            </a>
            <span class="badge bg-dark px-3 py-2 ms-2 shadow-sm">LOTE #{{ $id }}</span>
        </div>
    </div>

    

    {{-- Top dashboard: chart + cards --}}
    <div class="row g-4 mb-4 justify-content-center">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-3 h-100" style="border-radius: 15px;">
                <div class="card-header bg-white border-0 text-center pb-0">
                    <h6 class="fw-bold text-uppercase small text-muted mb-0">Evolution API distribution</h6>
                </div>
                <div class="card-body position-relative" style="height: 250px;">
                    <canvas id="jobsPieChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="row g-3 h-100">
                @foreach($cards as $card)
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm bg-{{ $card['color'] }} text-white p-4 h-100 position-relative overflow-hidden" style="border-radius: 15px;">
                        <i class="bi {{ $card['icon'] }} position-absolute end-0 bottom-0 mb-n2 me-n2 opacity-25" style="font-size: 5rem;"></i>
                        <div class="position-relative" style="z-index: 1;">
                            <small class="opacity-75 uppercase fw-bold" style="font-size: 0.75rem; letter-spacing: 0.5px;">{{ $card['label'] }}</small>
                            <h2 class="fw-bold mb-0 mt-1 display-6">{{ number_format($card['count'], 0, ',', '.') }}</h2>
                            <div class="d-flex align-items-center mt-2">
                                <div class="progress flex-grow-1 bg-white bg-opacity-25" style="height: 4px;">
                                    <div class="progress-bar bg-white" style="width: {{ $card['pct'] }}%"></div>
                                </div>
                                <small class="ms-2 fw-bold" style="font-size: 0.8rem;">{{ $card['pct'] }}%</small>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
        </div>
        <div>
            <button class="btn btn-outline-primary btn-sm fw-bold shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="bi bi-funnel-fill me-1"></i> FILTERS
            </button>
        </div>
    </div>

    {{-- Filters --}}
    <div class="collapse {{ request()->anyFilled(['contact', 'status', 'evolution_status']) ? 'show' : '' }} mb-4" id="filterCollapse">
        <div class="card border-0 shadow-sm card-body bg-white border-start border-primary border-4">
            <form method="GET" action="{{ route('whatsapp-jobs.index', $id) }}" class="row g-3">
                <div class="col-md-4">
                    <label class="x-small fw-bold text-uppercase text-muted mb-1">Contact search</label>
                    <input type="text" name="contact" class="form-control form-control-sm" value="{{ request('contact') }}" placeholder="Name or number...">
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-uppercase text-muted mb-1">Internal status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="processado" {{ request('status') == 'processado' ? 'selected' : '' }}>Success</option>
                        <option value="erro" {{ request('status') == 'erro' ? 'selected' : '' }}>Failed</option>
                        <option value="pendente" {{ request('status') == 'pendente' ? 'selected' : '' }}>Queued</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-uppercase text-muted mb-1">WhatsApp status</label>
                    <select name="evolution_status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach($statusMapEvolution as $key => $label)
                        <option value="{{ $key }}" {{ request('evolution_status') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold shadow-sm">FILTER</button>
                    <a href="{{ route('whatsapp-jobs.index', $id) }}" class="btn btn-light btn-sm w-100 fw-bold border">CLEAR</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Main jobs table --}}
    
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="jobsTable">
                <thead class="bg-light x-small text-uppercase fw-bold text-muted border-bottom">
                    <tr>
                        <th class="ps-4" style="width: 40px;">
                            <input type="checkbox" class="form-check-input shadow-none" id="selectAll" onclick="toggleSelectAll(this.checked)">
                        </th>
                        <th>Recipient</th>
                        <th>Internal status</th>
                        <th>WhatsApp status</th>
                        <th>Actions</th>
                        @if(Auth::user()->role === 'admin')
                        <th class="text-center">Error log</th>
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

                    // Contact row color: active vs no-WhatsApp
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
                                <i class="bi {{ $contactIcon }} {{ $contactClass }} me-2" style="font-size: 0.6rem;" title="{{ $isAtivo ? 'Active contact' : 'Invalid / inactive number' }}"></i>
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
                            <span class="text-muted opacity-50 italic small">Waiting...</span>
                            @endif
                        </td>
                        <td>
                            <div class="btn-group">
                                <button type="button" class="btn btn-xs btn-outline-secondary"
                                    onclick="window.copyToClipboard(this.getAttribute('data-content'), 'JSON copied to clipboard')"
                                    data-content='{!! $payloadJson !!}' title="Copy payload">
                                    <i class="bi bi-filetype-json"></i>
                                </button>

                                @if($job->status == 'erro')
                                <form action="{{ route('whatsapp-jobs.retry', $job->id) }}" method="POST" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-outline-danger shadow-none" title="Retry">
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
                                onclick="window.copyToClipboard(this.getAttribute('data-content'), 'Error copied to clipboard')"
                                data-content="{{ $job->erro_mensagem }}">
                                <i class="bi bi-bug-fill me-1"></i> Log
                            </button>
                            @else
                            <span class="text-muted small opacity-50">---</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-dark fw-mono"
                                onclick="window.copyToClipboard(this.getAttribute('data-content'), 'cURL copied to clipboard')"
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
                            No records found for the current filters.
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
    .x-small {
        font-size: 0.72rem;
    }

    .btn-xs {
        padding: 0.25rem 0.5rem;
        font-size: 0.7rem;
    }

    .fw-mono {
        font-family: 'Courier New', Courier, monospace;
        font-size: 0.7rem;
    }

    .badge {
        font-size: 0.65rem;
    }

    .form-control-sm,
    .form-select-sm {
        font-size: 0.8rem;
        border-radius: 6px;
    }

    .table-hover tbody tr:hover {
        background-color: #f8f9fc;
    }

    .pagination {
        margin-bottom: 0;
    }

    nav[role="navigation"] svg {
        width: 20px;
        height: 20px;
        display: inline;
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    /** --- Chart init --- **/
    document.addEventListener('DOMContentLoaded', function() {
        const ctx = document.getElementById('jobsPieChart');
        if (!ctx) return;

        const dataValues = [{{
                    $errorsCount
                }},
            {{
                    $sentCount
                }},
            {{
                    $deliveredCount
                }},
            {{
                     $readCount
                }}
        ];

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Errors', 'Sent', 'Delivered', 'Read'],
                datasets: [{
                    data: dataValues,
                    backgroundColor: ['#dc3545', '#0d6efd', '#0dcaf0', '#198754'],
                    hoverOffset: 15,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 10,
                            padding: 20,
                            font: {
                                size: 11
                            }
                        }
                    }
                },
                cutout: '75%'
            }
        });
    });

    /** --- Bulk selection --- **/
    function toggleSelectAll(checked) {
        document.querySelectorAll('.job-checkbox').forEach(cb => cb.checked = checked);
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) selectAllCheckbox.checked = checked;
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

        const confirmMsg = action === 'delete' ?
            `Really REMOVE ${ids.length} record(s)?` :
            `Resend ${ids.length} message(s) to the queue?`;

        if (!confirm(confirmMsg)) return;

        const route = action === 'delete' ?
            "{{ route('whatsapp-jobs.bulk-delete') }}" :
            "{{ route('whatsapp-jobs.bulk-retry') }}";

        document.getElementById('bulkActionForm').action = route;
        document.getElementById('bulkIdsInput').value = JSON.stringify(ids);
        document.getElementById('bulkActionInput').value = action;
        document.getElementById('bulkActionForm').submit();
    }

    /** --- Clipboard copy --- **/
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
        try {
            if (document.execCommand('copy')) alert(successMsg);
        } catch (err) {
            console.error(err);
        }
        document.body.removeChild(textArea);
    }
</script>
@endsection