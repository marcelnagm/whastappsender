@extends('layouts.app-master')

@section('template_title', 'Detalhes da Campanha: ' . $campaign->name)

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white overflow-hidden">
                <div class="card-body p-4 position-relative">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="text-uppercase opacity-75 small fw-bold mb-1">Gestão de Campanha</h6>
                            <h1 class="display-6 fw-bold mb-2">{{ $campaign->name }}</h1>
                            <div class="d-flex gap-3">
                                <span class="badge bg-white text-primary px-3 py-2 rounded-pill">
                                    <i class="bi bi-calendar3 me-1"></i> Criada em: {{ $campaign->created_at->format('d/m/Y') }}
                                </span>
                                <span class="badge bg-white text-primary px-3 py-2 rounded-pill">
                                    <i class="bi bi-person me-1"></i> Responsável: {{ $campaign->user->name ?? 'Sistema' }}
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            @php $overallRate = $campaign->getSuccessRate(); @endphp
                            <div class="d-inline-block text-center p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-25">
                                <h2 class="mb-0 fw-bold">{{ $overallRate }}%</h2>
                                <small class="text-uppercase x-small">Sucesso Global</small>
                            </div>
                        </div>
                    </div>
                    <i class="bi bi-megaphone position-absolute end-0 bottom-0 opacity-10 m-n3" style="font-size: 8rem;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="mb-0 fw-bold">Configurações</h6>
                </div>
                <div class="card-body pt-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted small">Total de Itens:</span>
                            <span class="fw-bold">{{ $campaign->campaignItems->count() }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted small">Status:</span>
                            <span class="badge bg-success">Ativa</span>
                        </li>
                    </ul>
                    <div class="d-grid gap-2 mt-4">
                        <a href="{{ route('campaigns.edit', $campaign->id) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i> Editar Campanha
                        </a>
                        <a href="{{ route('campaigns.index') }}" class="btn btn-light btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Voltar à Lista
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-9">
            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold text-primary">Itens de Disparo (Conteúdo)</h6>
                    <a href="{{ route('campaign-items.create', ['campaign_id' => $campaign->id]) }}" class="btn btn-primary btn-sm rounded-pill px-3">
                        <i class="bi bi-plus-lg"></i> Novo Item
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="overflow: visible !important;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4 border-0">Nome do Item</th>
                                    <th class="border-0">Mensagem</th>
                                    <th class="border-0 text-center">Mídia</th>
                                    <th class="border-0">Entrega ACK</th>
                                    <th class="border-0 text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignItems as $campaignItem)
                                    @php 
                                        $rate = $campaignItem->getDeliveryRate(); 
                                        $barClass = $rate >= 80 ? 'bg-success' : ($rate >= 50 ? 'bg-warning' : 'bg-danger');
                                    @endphp
                                    <tr>
                                        <td class="ps-4">
                                            <span class="fw-bold text-dark">{{ $campaignItem->name }}</span>
                                        </td>
                                        <td>
                                            <div class="text-truncate text-muted small" style="max-width: 180px;" title="{{ $campaignItem->text }}">
                                                {{ Str::limit($campaignItem->text, 40) }}
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            @if($campaignItem->image)
                                                <div class="media-tooltip-container">
                                                    <i class="bi bi-image text-primary fs-5"></i>
                                                    <div class="media-tooltip-content">
                                                        <img src="{{ $campaignItem->image }}" alt="Preview">
                                                    </div>
                                                </div>
                                            @else
                                                <i class="bi bi-chat-left-text text-light"></i>
                                            @endif
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 6px; border-radius: 10px;">
                                                    <div class="progress-bar {{ $barClass }}" style="width: {{ $rate }}%"></div>
                                                </div>
                                                <span class="ms-2 fw-bold small text-{{ str_replace('bg-', '', $barClass) }}">{{ $rate }}%</span>
                                            </div>
                                        </td>
                                        <td class="text-center pe-4">
                                            <div class="btn-group shadow-sm">
                                                <button type="button" class="btn btn-sm btn-primary dropdown-toggle px-3" data-bs-toggle="dropdown">
                                                    Executar
                                                </button>
                                                <ul class="dropdown-menu shadow border-0">
                                                    <li><a class="dropdown-item py-2" href="{{ route('campaign-items.generateAll',$campaignItem->id) }}">Gerar - TODOS</a></li>
                                                    <li><a class="dropdown-item py-2 text-success fw-bold" href="{{ route('campaign-items.send',$campaignItem->id) }}">Iniciar Disparo</a></li>
                                                </ul>
                                                <a class="btn btn-sm btn-light border-start" href="{{ route('campaign-items.edit',$campaignItem->id) }}"><i class="bi bi-pencil"></i></a>
                                                <a class="btn btn-sm btn-light border-start" href="{{ route('campaign-items.logs',$campaignItem->id) }}"><i class="bi bi-journal-text"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="py-3">
                {!! $campaignItems->links() !!}
            </div>
        </div>
    </div>
</div>

<style>
    /* Reaproveitando seus estilos de Tooltip e utilitários */
    .media-tooltip-container { position: relative; display: inline-block; cursor: pointer; }
    .media-tooltip-content {
        visibility: hidden; position: absolute; z-index: 10001; bottom: 125%; left: 50%; transform: translateX(-50%);
        width: 160px; background: #fff; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); opacity: 0; transition: 0.2s;
    }
    .media-tooltip-content img { width: 100%; border-radius: 8px; }
    .media-tooltip-container:hover .media-tooltip-content { visibility: visible; opacity: 1; }
    .x-small { font-size: 0.65rem; }
</style>
@endsection