@extends('layouts.app-master')

@section('template_title', 'Campanha: ' . $campaign->name)

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0 text-gray-800 fw-bold">
                        <i class="bi bi-megaphone text-primary me-2"></i> {{ $campaign->name }}
                    </h1>
                    <p class="text-muted mb-0 small">Criada por: {{ $campaign->user->name ?? 'Sistema' }} | Criada em: {{ $campaign->created_at->format('d/m/Y H:i') }}</p>
                </div>
                <div>
                    <a class="btn btn-outline-secondary shadow-sm" href="{{ route('campaigns.index') }}">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <a class="btn btn-success shadow-sm" href="{{ route('campaigns.edit', $campaign->id) }}">
                        <i class="bi bi-pencil"></i> Editar Campanha
                    </a>
                </div>
            </div>

            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card border-0 shadow-sm p-3">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-soft-primary p-3 me-3">
                                <i class="bi bi-chat-dots text-primary"></i>
                            </div>
                            <div>
                                <h6 class="text-muted mb-0 small">Total de Mensagens</h6>
                                <span class="h4 fw-bold mb-0">{{ $campaign->campaignItems->count() }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                {{-- Espaço para métricas futuras: Contatos vinculados, Envios feitos, etc --}}
            </div>

            <div class="card border-0 shadow-sm rounded-3">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="m-0 font-weight-bold text-dark">Sequência de Mensagens (Itens)</h5>
                    <a href="{{ route('campaign-items.create', ['campaign_id' => $campaign->id]) }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Adicionar Mensagem
                    </a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Nome do Item</th>
                                    <th>Mídia</th>
                                    <th>Texto da Mensagem</th>
                                    <th class="text-center">Ações Rápidas</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($campaign->campaignItems as $item)
                                    <tr>
                                        <td class="ps-4 text-muted">{{ $loop->iteration }}</td>
                                        <td class="fw-bold">{{ $item->name }}</td>
                                        <td>
                                            @if($item->image)
                                                <span class="badge bg-info text-dark preview-image" 
                                                      style="cursor: help;"
                                                      data-bs-toggle="popover" 
                                                      data-bs-trigger="hover focus"
                                                      data-bs-html="true"
                                                      data-bs-content="<img src='{{ $item->image }}' class='img-fluid rounded' style='max-width:180px;'>">
                                                    <i class="bi bi-image"></i> Ver
                                                </span>
                                            @else
                                                <span class="text-muted small">Sem Mídia</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small class="text-muted" title="{{ $item->text }}">
                                                {{ Str::limit($item->text, 60) }}
                                            </small>
                                        </td>
                                        <td class="text-center pe-4">
                                            <div class="btn-group shadow-sm">
                                                <a href="{{ route('campaign-items.show', $item->id) }}" class="btn btn-sm btn-light border" title="Ver Detalhes">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="{{ route('campaign-items.edit', $item->id) }}" class="btn btn-sm btn-light border text-primary" title="Editar">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <a href="{{ route('campaign-items.send', $item->id) }}" class="btn btn-sm btn-success border" title="Disparar este item">
                                                    <i class="bi bi-send"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="bi bi-chat-quote fs-1 d-block mb-3 opacity-25"></i>
                                            Nenhuma mensagem configurada para esta campanha.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .bg-soft-primary { background-color: rgba(13, 110, 253, 0.1); }
    .popover { border: none; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.2); }
</style>
@endsection

@section('js')
<script>
    $(document).ready(function() {
        // Inicializa popover para as imagens dos itens
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
        })
    });
</script>
@endsection