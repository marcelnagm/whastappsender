@extends('layouts.app-master')

@section('template_title')
    Editar Contato: {{ $contact->name }}
@endsection

@section('content')
    <div class="container-fluid">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-pencil-square text-primary me-2"></i>Editar Contato
                </h1>
                <p class="text-muted small mb-0">Atualizando os dados de: <strong>{{ $contact->name }}</strong> (ID: #{{ $contact->id }})</p>
            </div>
            <div class="d-flex gap-2">
                <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary btn-sm shadow-sm">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                
                @includeif('partials.errors')

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-0 py-3 border-bottom">
                        <span class="fw-bold text-muted text-uppercase small">Ajustar Credenciais</span>
                    </div>
                    
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('contacts.update', $contact->id) }}" role="form" enctype="multipart/form-data">
                            {{ method_field('PATCH') }}
                            @csrf

                            @include('contact.form')

                        </form>
                    </div>
                </div>

                <div class="mt-4 text-center">
                    <p class="text-muted x-small">Última atualização: {{ $contact->updated_at->format('d/m/Y H:i') }}</p>
                </div>
            </div>
        </div>
    </div>

<style>
    .x-small { font-size: 0.75rem; }
</style>
@endsection