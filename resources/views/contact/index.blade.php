@extends('layouts.app-master')

@section('template_title', 'Gerenciamento de Contatos')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0 text-gray-800"><i class="bi bi-people-fill"></i> Base de Contatos</h1>
                <div>
                    <button class="btn btn-outline-secondary btn-sm me-2" type="button" data-bs-toggle="collapse" data-bs-target="#collapseImport" aria-expanded="false">
                        <i class="bi bi-file-earmark-arrow-up"></i> Importar CSV
                    </button>
                    <a href="{{ route('contacts.create') }}" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Novo Contato
                    </a>
                </div>
            </div>

            <div class="collapse mb-4" id="collapseImport">
                <div class="card card-body border-primary shadow-sm">
                    <h5 class="card-title">Importação em Massa</h5>
                    <form method="POST" action="{{ route('contacts.import') }}" enctype="multipart/form-data" class="row g-3 align-items-center">
                        @csrf
                        <div class="col-auto">
                            <input type="file" name="importer" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-auto">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="renover" value="1" id="checkRemove">
                                <label class="form-check-label text-danger small" for="checkRemove">
                                    Limpar base antes de importar
                                </label>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-success btn-sm">Iniciar Processamento</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Listagem de Leads</h6>
                    <a href="{{ route('contacts.clear') }}" class="btn btn-link text-danger btn-sm p-0" onclick="return confirm('ATENÇÃO: Isso apagará TODOS os contatos minerados. Confirma?')">
                        <i class="bi bi-trash3"></i> Esvaziar Base
                    </a>
                </div>

                @if ($message = Session::get('success'))
                    <div class="alert alert-success border-0 rounded-0 m-0">
                        <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
                    </div>
                @endif

                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Nome</th>
                                    <th>WhatsApp / Contato</th>
                                    <th>E-mail</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contacts as $contact)
                                    <tr>
                                        <td>{{ $contact->id }}</td>
                                        <td class="fw-bold">{{ $contact->name }}</td>
                                        <td>
                                            <a href="https://wa.me/{{ $contact->contact }}" target="_blank" class="text-decoration-none">
                                                <i class="bi bi-whatsapp text-success"></i> {{ $contact->contact }}
                                            </a>
                                        </td>
                                        <td class="text-muted">{{ $contact->email }}</td>
                                        <td class="text-center">
                                            <div class="btn-group shadow-sm">
                                                <a class="btn btn-sm btn-light" href="{{ route('contacts.show',$contact->id) }}" title="Visualizar"><i class="bi bi-eye"></i></a>
                                                <a class="btn btn-sm btn-light text-primary" href="{{ route('contacts.edit',$contact->id) }}" title="Editar"><i class="bi bi-pencil"></i></a>
                                                <form action="{{ route('contacts.destroy',$contact->id) }}" method="POST" style="display:inline">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-light text-danger" title="Excluir" onclick="return confirm('Excluir este contato?')"><i class="bi bi-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white">
                    {!! $contacts->links() !!}
                </div>
            </div>
        </div>
    </div>
</div>
@endsection