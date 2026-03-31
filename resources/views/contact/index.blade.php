@extends('layouts.app-master')

@section('template_title', 'Gerenciamento de Contatos')

@section('content')
<div class="container-fluid py-4">
    {{-- BARRA DE AÇÕES EM MASSA (STICKY BULK BAR) --}}
    <div id="bulkActionsBar" class="card border-0 shadow-lg bg-dark text-white position-fixed bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1050; min-width: 500px; border-radius: 50px;">
        <div class="card-body d-flex align-items-center justify-content-between py-2 px-4">
            <div class="small">
                <span id="selectedCount" class="fw-bold text-warning">0</span> selecionados
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm fw-bold rounded-pill" onclick="submitBulk('ativo')">
                    <i class="bi bi-check-circle me-1"></i> ATIVAR
                </button>
                <button type="button" class="btn btn-secondary btn-sm fw-bold rounded-pill" onclick="submitBulk('inativo')">
                    <i class="bi bi-slash-circle me-1"></i> INATIVAR
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

    {{-- Formulário Oculto para Bulk Action --}}
    <form id="bulkActionForm" method="POST" action="" class="d-none">
        @csrf
        <input type="hidden" name="ids" id="bulkIdsInput">
        <input type="hidden" name="status_value" id="bulkStatusInput">
    </form>

    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-people-fill text-primary me-2"></i>Base de Contatos
                </h1>
                <div class="d-flex gap-2">
                    <form method="GET" action="{{ route('contacts.index') }}" class="d-flex gap-1 shadow-sm rounded bg-white">
                        <input type="text" name="search" class="form-control form-control-sm border-0 px-3 shadow-none"
                            placeholder="Nome, zap ou e-mail..." value="{{ request('search') }}" style="min-width: 250px;">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                        @if(request('search'))
                        <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary btn-sm border-0">
                            <i class="bi bi-x-lg"></i>
                        </a>
                        @endif
                    </form>

                    <a href="/modelo.xlsx" class="btn btn-outline-success btn-sm fw-bold">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Modelo
                    </a>
                    <button class="btn btn-outline-secondary btn-sm fw-bold" type="button" data-bs-toggle="collapse" data-bs-target="#collapseImport">
                        <i class="bi bi-file-earmark-arrow-up"></i> Importar
                    </button>
                    <a href="{{ route('contacts.create') }}" class="btn btn-success btn-sm fw-bold px-3">
                        <i class="bi bi-plus-lg"></i> NOVO
                    </a>
                </div>
            </div>

            {{-- Collapse Importação --}}
            <div class="collapse mb-4" id="collapseImport">
                <div class="card card-body border-primary shadow-sm border-2">
                    <h5 class="card-title fw-bold">Importação em Massa (CSV)</h5>
                    <form method="POST" action="{{ route('contacts.import') }}" enctype="multipart/form-data" class="row g-3 align-items-center">
                        @csrf
                        <div class="col-auto">
                            <input type="file" name="importer" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-auto">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="renover" value="1" id="checkRemove">
                                <label class="form-check-label text-danger small fw-bold" for="checkRemove">
                                    LIMPAR BASE ATUAL
                                </label>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold">PROCESSAR CSV</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Listagem de Leads ({{ $contacts->total() }})</h6>
                    <a href="{{ route('contacts.clear') }}" class="btn btn-link text-danger btn-sm p-0 text-decoration-none fw-bold small"
                        onclick="return confirm('ATENÇÃO: Isso apagará TODOS os seus contatos. Confirma?')">
                        <i class="bi bi-trash3"></i> ESVAZIAR BASE
                    </a>
                </div>

                @if ($message = Session::get('success'))
                <div class="alert alert-success border-0 rounded-0 m-0">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
                </div>
                @endif

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="contactsTable">
                            <thead class="table-light x-small text-uppercase fw-bold text-muted">
                                <tr>
                                    <th class="ps-4" style="width: 40px;">
                                        <input type="checkbox" class="form-check-input shadow-none" id="selectAll" onclick="toggleSelectAll(this.checked)">
                                    </th>
                                    <th>ID</th>
                                    <th>Nome / LID</th>
                                    <th>WhatsApp</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($contacts as $contact)
                                <tr id="row-{{ $contact->id }}">
                                    <td class="ps-4">
                                        <input type="checkbox" class="form-check-input contact-checkbox shadow-none" value="{{ $contact->id }}" onclick="updateBulkBar()">
                                    </td>
                                    <td class="text-muted small">#{{ $contact->id }}</td>
                                    <td>
                                        <div class="fw-bold text-dark">{{ $contact->name }}</div>
                                        @if($contact->lid)
                                        <span class="badge bg-light text-primary border fw-normal" style="font-size: 0.65rem;">
                                            <i class="bi bi-tag-fill me-1"></i>{{ $contact->lid }}
                                        </span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact->contact) }}" target="_blank" class="text-decoration-none small fw-bold">
                                                <i class="bi bi-whatsapp text-success me-1"></i>{{ $contact->contact }}
                                            </a>
                                            <span class="text-muted" style="font-size: 0.75rem;">{{ $contact->email ?? '---' }}</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold {{ $contact->score > 50 ? 'text-success' : 'text-muted' }}">
                                            {{ $contact->score }}
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        @if($contact->status === 'ativo')
                                        <span class="badge bg-success-soft text-success rounded-pill px-3">Ativo</span>
                                        @elseif($contact->status === 'no-whatsapp')
                                        <span class="badge bg-danger-soft text-danger rounded-pill px-3">No-WA</span>
                                        @else
                                        <span class="badge bg-secondary-soft text-secondary rounded-pill px-3">{{ $contact->status }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm shadow-sm border rounded bg-white">
                                            <a class="btn btn-white border-end" href="{{ route('contacts.show',$contact->id) }}" title="Visualizar"><i class="bi bi-eye"></i></a>
                                            <a class="btn btn-white border-end text-primary" href="{{ route('contacts.edit',$contact->id) }}" title="Editar"><i class="bi bi-pencil"></i></a>
                                            <form action="{{ route('contacts.destroy',$contact->id) }}" method="POST" style="display:inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-white text-danger" onclick="return confirm('Excluir contato?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="100%" class="text-center py-5 text-muted">
                                        <i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i>
                                        Nenhum contato encontrado.
                                    </td>
                                </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="py-4 d-flex justify-content-center border-top bg-light">
                    {{ $contacts->appends(request()->query())->links('pagination::bootstrap-4') }}
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .x-small {
        font-size: 0.72rem;
    }

    .bg-success-soft {
        background-color: #e8f5e9;
    }

    .bg-danger-soft {
        background-color: #ffebee;
    }

    .bg-secondary-soft {
        background-color: #f5f5f5;
    }

    .btn-white {
        background: white;
        border: none;
    }

    .btn-white:hover {
        background: #f8f9fa;
    }

    /* Paginação fix */
    nav[role="navigation"] svg {
        width: 20px;
        height: 20px;
    }

    .pagination {
        margin-bottom: 0;
    }
</style>

<script>
    function toggleSelectAll(checked) {
        document.querySelectorAll('.contact-checkbox').forEach(cb => cb.checked = checked);
        document.getElementById('selectAll').checked = checked;
        updateBulkBar();
    }

    function updateBulkBar() {
        const selected = document.querySelectorAll('.contact-checkbox:checked');
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
        const ids = Array.from(document.querySelectorAll('.contact-checkbox:checked')).map(cb => cb.value);
        if (ids.length === 0) return;

        let confirmMsg = '';
        let route = '';

        if (action === 'delete') {
            confirmMsg = `Deseja realmente REMOVER ${ids.length} contatos permanentemente?`;
            route = "{{ route('contacts.bulk-delete') }}";
        } else {
            confirmMsg = `Deseja alterar o status de ${ids.length} contatos para '${action}'?`;
            route = "{{ route('contacts.bulk-status') }}";
        }

        if (!confirm(confirmMsg)) return;

        document.getElementById('bulkActionForm').action = route;
        document.getElementById('bulkIdsInput').value = JSON.stringify(ids);
        document.getElementById('bulkStatusInput').value = action;
        document.getElementById('bulkActionForm').submit();
    }
</script>
@endsection