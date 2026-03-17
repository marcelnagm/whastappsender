@extends('layouts.app-master')

@section('template_title', 'Gerenciamento de Contatos')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-people-fill text-primary me-2"></i>Base de Contatos
                </h1>
                <div class="d-flex gap-2">
                    <form method="GET" action="{{ route('contacts.index') }}" class="d-flex gap-1 shadow-sm rounded">
                        <input type="text" name="search" class="form-control form-control-sm border-0 px-3"
                            placeholder="Nome, zap ou e-mail..." value="{{ request('search') }}" style="min-width: 250px;">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                        @if(request('search'))
                        <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x-lg"></i>
                        </a>
                        @endif
                    </form>
                    <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseImport">
                        <i class="bi bi-file-earmark-arrow-up"></i> Importar
                    </button>
                    <a href="{{ route('contacts.create') }}" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-lg"></i> Novo
                    </a>
                </div>
            </div>

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
                            <button type="submit" class="btn btn-primary btn-sm px-4">Processar</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Listagem de Leads ({{ $contacts->total() }})</h6>
                    <a href="{{ route('contacts.clear') }}" class="btn btn-link text-danger btn-sm p-0 text-decoration-none fw-bold"
                        onclick="return confirm('ATENÇÃO: Isso apagará TODOS os seus contatos. Confirma?')">
                        <i class="bi bi-trash3"></i> Esvaziar Base
                    </a>
                </div>

                @if ($message = Session::get('success'))
                <div class="alert alert-success border-0 rounded-0 m-0">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
                </div>
                @endif

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4">ID</th>
                                    <th>Nome</th>
                                    <th>WhatsApp / Contato</th>
                                    <th>E-mail</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($contacts as $contact)
                                <tr>
                                    <td class="ps-4">{{ $contact->id }}</td>
                                    <td class="fw-bold">{{ $contact->name }}</td>
                                    <td>
                                        <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact->contact) }}" target="_blank" class="text-decoration-none">
                                            <i class="bi bi-whatsapp text-success"></i> {{ $contact->contact }}
                                        </a>
                                    </td>
                                    <td class="text-muted small">{{ $contact->email }}</td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm shadow-sm border rounded">
                                            <a class="btn btn-white" href="{{ route('contacts.show',$contact->id) }}"><i class="bi bi-eye"></i></a>
                                            <a class="btn btn-white text-primary" href="{{ route('contacts.edit',$contact->id) }}"><i class="bi bi-pencil"></i></a>
                                            <form action="{{ route('contacts.destroy',$contact->id) }}" method="POST" style="display:inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-white text-danger" onclick="return confirm('Excluir?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr>
                                    <td colspan="5" class="text-center py-5 text-muted">
                                        Nenhum contato encontrado para "{{ request('search') }}".
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
    /* Correção do Paginate Gigante */
    nav[role="navigation"] svg {
        width: 20px;
        height: 20px;
    }

    .pagination {
        margin-bottom: 0;
    }

    .btn-white {
        background: white;
        border: none;
    }

    .btn-white:hover {
        background: #f8f9fa;
    }
</style>
@endsection