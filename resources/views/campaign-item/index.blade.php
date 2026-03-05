@extends('layouts.app-master')

@section('template_title', 'Itens de Campanha')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800 fw-bold"><i class="bi bi-list-check text-primary"></i> Itens de Campanha</h1>
                <a href="{{ route('campaign-items.create') }}" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-lg"></i> Novo Item
                </a>
            </div>

            @include('layouts.partials.messages')

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive" style="overflow: visible !important;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4">Item / Campanha</th>
                                    <th>Mensagem (Prévia)</th>
                                    <th class="text-center">Mídia</th>
                                    <th>Taxa de Entrega (ACK)</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignItems as $campaignItem)
                                    @php $rate = $campaignItem->getDeliveryRate(); @endphp
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark">{{ $campaignItem->name }}</div>
                                            <span class="badge bg-secondary opacity-75 x-small">
                                                {{ $campaignItem->campaign->name ?? 'N/A' }}
                                            </span>
                                        </td>
                                        
                                        <td class="text-truncate" style="max-width: 250px;">
                                            {{ Str::limit($campaignItem->text, 50) }}
                                        </td>

                                        <td class="text-center">
                                            @if($campaignItem->image)
                                                <div class="media-tooltip-container">
                                                    <i class="bi bi-image text-info fs-5"></i>
                                                    <div class="media-tooltip-content">
                                                        <img src="{{ $campaignItem->image }}" alt="Preview">
                                                        <div class="p-1 text-center small text-white bg-dark">Visualização</div>
                                                    </div>
                                                </div>
                                            @else
                                                <span class="text-muted small">Texto</span>
                                            @endif
                                        </td>

                                        <td style="min-width: 180px;">
                                            <div class="d-flex align-items-center">
                                                <div class="progress flex-grow-1" style="height: 8px; border-radius: 5px; background-color: #eee;">
                                                    <div class="progress-bar {{ $rate >= 80 ? 'bg-success' : ($rate >= 50 ? 'bg-warning' : 'bg-danger') }}" 
                                                         style="width: {{ $rate }}%"></div>
                                                </div>
                                                <span class="ms-2 fw-bold small text-{{ $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger') }}">
                                                    {{ $rate }}%
                                                </span>
                                            </div>
                                        </td>

                                        <td class="text-center pe-4">
                                            <div class="btn-group shadow-sm">
                                                <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                    <i class="bi bi-play-fill"></i> Executar
                                                </button>
                                                <ul class="dropdown-menu shadow border-0">
                                                    <li><a class="dropdown-item" href="{{ route('campaign-items.generateAll',$campaignItem->id) }}"><i class="bi bi-layers me-2"></i> Gerar - TODOS Contatos</a></li>
                                                    <li><a class="dropdown-item" href="{{ route('campaign-items.generate',$campaignItem->id) }}"><i class="bi bi-lightning me-2"></i> Gerar - TESTE</a></li>
                                                    <li><hr class="dropdown-divider"></li>
                                                    <li><a class="dropdown-item text-success fw-bold" href="{{ route('campaign-items.send',$campaignItem->id) }}"><i class="bi bi-send-check me-2"></i> Iniciar Disparo</a></li>
                                                </ul>

                                                <a class="btn btn-sm btn-light" href="{{ route('campaign-items.show',$campaignItem->id) }}" title="Visualizar"><i class="bi bi-eye"></i></a>
                                                <a class="btn btn-sm btn-light text-success" href="{{ route('campaign-items.edit',$campaignItem->id) }}" title="Editar"><i class="bi bi-pencil"></i></a>
                                                
                                                <form action="{{ route('campaign-items.destroy',$campaignItem->id) }}" method="POST" style="display:inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-light text-danger" title="Excluir" onclick="return confirm('Excluir item?')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
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
    /* Estilo do Tooltip CSS */
    .media-tooltip-container { position: relative; display: inline-block; cursor: pointer; }
    .media-tooltip-content {
        visibility: hidden; position: absolute; z-index: 10000;
        bottom: 125%; left: 50%; transform: translateX(-50%);
        width: 180px; background: #fff; border-radius: 8px;
        box-shadow: 0 8px 20px rgba(0,0,0,0.2); opacity: 0;
        transition: opacity 0.2s; border: 1px solid #ddd;
    }
    .media-tooltip-content img { width: 100%; height: auto; display: block; border-radius: 8px 8px 0 0; }
    .media-tooltip-container:hover .media-tooltip-content { visibility: visible; opacity: 1; }
    
    .x-small { font-size: 0.7rem; }
    .table-hover tbody tr:hover { background-color: #f8fafc; }
</style>
@endsection