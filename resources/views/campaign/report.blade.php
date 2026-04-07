@extends('layouts.app-master')

@section('content')
<div class="container py-4">
    {{-- Header --}}
    <div class="mb-4 d-flex justify-content-between align-items-end">
        <div>
            <h2 class="fw-bold mb-1 text-dark">Relatório de Performance</h2>
            <p class="text-muted mb-0">Campanha: <span class="text-primary fw-semibold">{{ $campaign->name }}</span></p>
        </div>
        <div class="text-end text-muted small">
            <i class="bi bi-calendar3 me-1"></i> {{ $campaign->created_at->format('d/m/H:i') }}
        </div>
    </div>

    {{-- Dashboard: Gráfico + Cards --}}
    <div class="row g-4 mb-5">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-3 h-100">
                <div class="card-header bg-white border-0 text-center pb-0">
                    <h6 class="fw-bold text-uppercase small text-muted mb-0">Distribuição</h6>
                </div>
                <div class="card-body" style="height: 250px;">
                    <canvas id="campaignPieChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="row g-3 h-100">
                @php
                    $cards = [
                        ['l' => 'Erros', 'c' => 'danger', 'v' => $errors['count'] ?? 0, 'p' => $errors['percent'] ?? 0, 'i' => 'bi-x-circle'],
                        ['l' => 'Enviados', 'c' => 'primary', 'v' => $sent['count'] ?? 0, 'p' => $sent['percent'] ?? 0, 'i' => 'bi-send'],
                        ['l' => 'Entregues', 'c' => 'info', 'v' => $delivered['count'] ?? 0, 'p' => $delivered['percent'] ?? 0, 'i' => 'bi-check2-all'],
                        ['l' => 'Lidos', 'c' => 'success', 'v' => $read['count'] ?? 0, 'p' => $read['percent'] ?? 0, 'i' => 'bi-eye']
                    ];
                @endphp

                @foreach($cards as $card)
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm bg-{{ $card['c'] }} text-white p-4 h-100 position-relative overflow-hidden">
                            <i class="bi {{ $card['i'] }} position-absolute end-0 bottom-0 mb-n2 me-n2 opacity-25" style="font-size: 5rem;"></i>
                            <small class="opacity-75 uppercase fw-bold small">{{ $card['l'] }}</small>
                            <h2 class="fw-bold mb-0">{{ $card['v'] }}</h2>
                            <div class="fw-semibold mt-1">{{ $card['p'] }}%</div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Tabela --}}
    <div class="card border-0 shadow-sm overflow-visible" style="border-radius: 15px;">
        <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
            <table class="table align-middle mb-0 text-center table-hover sticky-table">
                <thead class="bg-light small text-uppercase text-muted">
                    <tr>
                        <th class="text-start ps-4 py-3">Item ID</th>
                        <th>Total</th>
                        <th class="text-danger">Erros</th>
                        <th class="text-primary">Enviados</th>
                        <th class="text-info">Entregues</th>
                        <th class="text-success">Lidos</th>
                        <th class="text-end pe-4">Ação</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $item)
                        @php 
                            $s = is_string($item->summary()) ? json_decode($item->summary()) : (object) $item->summary();
                            $itemTotal = ($s->total ?? 0) ?: 1;
                        @endphp
                        <tr>
                            <td class="text-start ps-4 fw-bold">#{{ substr($item->id, 0, 8) }}</td>
                            <td><span class="badge bg-light text-dark border">{{ $s->total ?? 0 }}</span></td>
                            <td>{{ $s->errors ?? 0 }} <br><small class="text-muted">{{ round((($s->errors ?? 0) / $itemTotal) * 100, 1) }}%</small></td>
                            <td>{{ $s->sent ?? 0 }} <br><small class="text-muted">{{ round((($s->sent ?? 0) / $itemTotal) * 100, 1) }}%</small></td>
                            <td>{{ $s->delivered ?? 0 }} <br><small class="text-muted">{{ round((($s->delivered ?? 0) / $itemTotal) * 100, 1) }}%</small></td>
                            <td>{{ $s->read_count ?? 0 }} <br><small class="text-muted">{{ round((($s->read_count ?? 0) / $itemTotal) * 100, 1) }}%</small></td>
                            <td class="text-end pe-4">
                                <a href="{{ route('campaign-items.show', $item->id) }}" class="btn btn-sm btn-outline-primary px-3">Ver</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-5 text-muted">Sem dados.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if(method_exists($items, 'links'))
            <div class="card-footer bg-white border-0 py-4 d-flex justify-content-center">
                {{ $items->links() }}
            </div>
        @endif
        </div>
    </div>
</div>

{{-- Scripts --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const ctx = document.getElementById('campaignPieChart');
        if (!ctx) return;

        // Forçamos o valor a ser numérico (int) para o JS não receber vácuo ou strings
        const dataValues = [
            {{ (int)($errors['count'] ?? 0) }},
            {{ (int)($sent['count'] ?? 0) }},
            {{ (int)($delivered['count'] ?? 0) }},
            {{ (int)($read['count'] ?? 0) }}
        ];

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['Erros', 'Enviados', 'Entregues', 'Lidos'],
                datasets: [{
                    data: dataValues,
                    backgroundColor: ['#dc3545', '#0d6efd', '#0dcaf0', '#198754'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '75%'
            }
        });
    });
</script>

<style>
    .sticky-table thead th { position: sticky; top: 0; z-index: 10; background: #f8f9fa !important; border-bottom: 2px solid #dee2e6 !important; }
    .table td { padding: 1rem 0.5rem; border-bottom: 1px solid #f1f5f9; }
</style>
<style>
    /* "Mata" os ícones gigantes da paginação do Tailwind */
    nav[role="navigation"] svg {
        width: 20px;
        height: 20px;
        display: inline;
    }
    
    .pagination {
        margin-top: 1rem;
        margin-bottom: 0;
    }

    /* Garante que o texto de "Showing X to Y" fique pequeno */
    .relative.inline-flex.items-center.px-4.py-2 {
        padding: 5px 10px;
        font-size: 14px;
    }
</style>
@endsection