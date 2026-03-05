@extends('layouts.app-master')

@section('template_title', 'Itens de Campanha')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="bi bi-list-check text-primary"></i> Itens de Campanha</h1>
                <a href="{{ route('campaign-items.create') }}" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-lg"></i> Novo Item
                </a>
            </div>

            @include('layouts.partials.messages')

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">#</th>
                                    <th>Nome</th>
                                    <th>Mensagem (Prévia)</th>
                                    <th>Mídia</th>
                                    <th>Campanha</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaignItems as $campaignItem)
                                    <tr>
                                        <td class="ps-4 text-muted">{{ ++$i }}</td>
                                        <td class="fw-bold">{{ $campaignItem->name }}</td>
                                        <td class="text-truncate" style="max-width: 250px;">
                                            {{ Str::limit($campaignItem->text, 50) }}
                                        </td>
                                        <td>
                                            @if($campaignItem->image)
                                                <span class="badge bg-info text-dark preview-image" 
                                                      style="cursor: help;"
                                                      data-bs-toggle="popover" 
                                                      data-bs-trigger="hover focus"
                                                      data-bs-html="true"
                                                      data-bs-content="<img src='{{ $campaignItem->image }}' class='img-fluid rounded' style='max-width:200px;'>">
                                                    <i class="bi bi-image"></i> Ver Imagem
                                                </span>
                                            @else
                                                <span class="badge bg-light text-muted border">Apenas Texto</span>
                                            @endif
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary opacity-75">
                                                {{ $campaignItem->campaign->name ?? 'N/A' }}
                                            </span>
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
    /* Customização do Popover para a Imagem */
    .popover { border: none; box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.2); }
    .popover-body { padding: 5px; }
    .table-hover tbody tr:hover { background-color: #f1f5f9; }
</style>
@endsection

@section('js')
<script>
    $(document).ready(function() {
        // Inicializa todos os popovers da página
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'))
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl)
        })
    });
</script>
@endsection