@extends('layouts.app-master')

@section('template_title')
    Perfil: {{ $contact->name }}
@endsection

@section('content')
<div class="container-fluid py-4">
    {{-- Header de Navegação --}}
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800 fw-bold">
                <i class="bi bi-person-badge text-primary me-2"></i>Detalhes do Lead
            </h1>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm fw-bold shadow-sm" href="{{ route('contacts.index') }}">
                <i class="bi bi-arrow-left me-1"></i> VOLTAR
            </a>
            <a class="btn btn-primary btn-sm fw-bold shadow-sm" href="{{ route('contacts.edit', $contact->id) }}">
                <i class="bi bi-pencil me-1"></i> EDITAR
            </a>
        </div>
    </div>

    <div class="row">
        {{-- Card de Informações do Contato --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-body p-4">
                    <div class="text-center mb-4">
                        <div class="bg-light d-inline-block p-4 rounded-circle mb-3">
                            <i class="bi bi-person-fill fs-1 text-primary"></i>
                        </div>
                        <h4 class="fw-bold mb-1">{{ $contact->name }}</h4>
                        <span class="badge bg-light text-primary border px-3">#{{ $contact->id }}</span>
                    </div>

                    <div class="list-group list-group-flush border-top pt-3">
                        <div class="list-group-item border-0 px-0 py-2">
                            <label class="x-small fw-bold text-muted text-uppercase d-block">WhatsApp</label>
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact->contact) }}" target="_blank" class="text-decoration-none fw-bold text-dark">
                                <i class="bi bi-whatsapp text-success me-1"></i>{{ $contact->contact }}
                            </a>
                        </div>
                        <div class="list-group-item border-0 px-0 py-2">
                            <label class="x-small fw-bold text-muted text-uppercase d-block">E-mail</label>
                            <span class="text-dark small fw-medium">{{ $contact->email ?? 'N/A' }}</span>
                        </div>
                        <div class="list-group-item border-0 px-0 py-2 d-flex justify-content-between align-items-center">
                            <div>
                                <label class="x-small fw-bold text-muted text-uppercase d-block">LID / Origem</label>
                                <span class="badge bg-info-subtle text-info">{{ $contact->lid ?? 'Orgânico' }}</span>
                            </div>
                            <div class="text-end">
                                <label class="x-small fw-bold text-muted text-uppercase d-block">Score</label>
                                <span class="fw-bold text-primary">{{ $contact->score }}</span>
                            </div>
                        </div>
                        <div class="list-group-item border-0 px-0 py-2">
                            <label class="x-small fw-bold text-muted text-uppercase d-block">Status</label>
                            <span class="badge rounded-pill px-3 {{ $contact->status === 'ativo' ? 'bg-success' : 'bg-danger' }}">
                                {{ strtoupper($contact->status) }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Lista de Jobs Relacionados --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-dark text-uppercase x-small">
                        <i class="bi bi-chat-left-dots-fill me-2 text-primary"></i>Histórico de Envios (Jobs)
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light x-small text-muted text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4">ID Job</th>
                                    <th>Status Interno</th>
                                    <th>Status WA</th>
                                    <th>Data</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php $jobs = $contact->whatsappjobs()->orderBy('created_at', 'desc')->get(); @endphp
                                @forelse ($jobs as $job)
                                <tr>
                                    <td class="ps-4 small text-muted">#{{ $job->id }}</td>
                                    <td>
                                        <span class="badge rounded-pill px-3 
                                            {{ $job->status == 'processado' ? 'bg-success-soft text-success' : ($job->status == 'erro' ? 'bg-danger-soft text-danger' : 'bg-warning-soft text-warning') }}">
                                            {{ strtoupper($job->status) }}
                                        </span>
                                    </td>
                                    <td class="small fw-bold text-primary">
                                        {{ strtoupper($job->evolution_status ?? 'Pendente') }}
                                    </td>
                                    <td class="x-small text-muted">
                                        {{ $job->created_at->format('d/m/Y H:i') }}
                                    </td>
                                    <td class="text-center">
                                        @if($job->status == 'erro')
                                            <form action="{{ route('whatsapp-jobs.retry', $job->id) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-danger py-1 px-3 fw-bold" style="font-size: 0.65rem;">
                                                    <i class="bi bi-arrow-repeat me-1"></i> RETENTAR
                                                </button>
                                            </form>
                                        @else
                                            <span class="text-muted opacity-50">---</span>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted italic">
                                        Nenhum Job de envio registrado para este contato.
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
    .x-small { font-size: 0.7rem; }
    .bg-success-soft { background-color: #e8f5e9; color: #2e7d32; }
    .bg-danger-soft { background-color: #ffebee; color: #c62828; }
    .bg-warning-soft { background-color: #fff8e1; color: #f57f17; }
</style>
@endsection