@extends('layouts.app-master')

@section('template_title', 'Gestão de Campanhas')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0 text-gray-800"><i class="bi bi-megaphone-fill text-primary"></i> Campanhas de Disparo</h1>
                    <p class="text-muted small">Gerencie e monitore o desempenho dos seus disparos em massa.</p>
                </div>
                <a href="{{ route('campaigns.create') }}" class="btn btn-primary shadow-sm">
                    <i class="bi bi-plus-lg"></i> Criar Nova Campanha
                </a>
            </div>

            @if ($message = Session::get('success'))
                <div class="alert alert-success border-0 shadow-sm mb-4">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
                </div>
            @endif

            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="ps-4">ID</th>
                                    <th>Nome da Campanha</th>
                                    <th>Progresso / Entrega</th>
                                    <th>Status</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaigns as $campaign)
                                    <tr>
                                        <td class="ps-4 text-muted">#{{ $campaign->id }}</td>
                                        <td>
                                            <div class="fw-bold text-dark">{{ $campaign->name }}</div>
                                            <small class="text-muted">Criado por: {{ $campaign->user->name ?? 'Sistema' }}</small>
                                        </td>
                                        <td style="min-width: 200px;">
                                            <div class="d-flex justify-content-between mb-1 small">
                                                <span>Taxa de Sucesso</span>
                                                @php $rate = $campaign->getSuccessRate(); @endphp
                                                <span class="fw-bold">{{ $rate }}%</span> </div>
                                            <div class="progress" style="height: 6px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: {{ $rate }}%"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge rounded-pill bg-soft-success text-success border border-success px-3">
                                                <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> Ativa
                                            </span>
                                        </td>
                                        <td class="text-center pe-4">
                                            <div class="btn-group shadow-sm">
                                                <a class="btn btn-sm btn-light" href="{{ route('campaigns.show',$campaign->id) }}" title="Ver Detalhes">
                                                    <i class="bi bi-graph-up-arrow"></i>
                                                </a>
                                                <a class="btn btn-sm btn-light text-primary" href="{{ route('campaigns.edit',$campaign->id) }}" title="Editar">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form action="{{ route('campaigns.destroy',$campaign->id) }}" method="POST" style="display:inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-light text-danger" title="Excluir" onclick="return confirm('Excluir campanha e logs?')">
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
                <div class="card-footer bg-white border-0 py-3">
                    {!! $campaigns->links() !!}
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Estilo para Badges mais modernos */
    .bg-soft-success { background-color: #d1e7dd; color: #0f5132; }
    .table-hover tbody tr:hover { background-color: #f8fafc; }
    .btn-group .btn-light { border: 1px solid #e2e8f0; }
</style>
@endsection