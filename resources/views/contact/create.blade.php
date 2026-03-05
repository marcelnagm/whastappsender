@extends('layouts.app-master')

@section('template_title')
    Novo Contato - Mining System
@endsection

@section('content')
    <div class="container-fluid">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-person-plus text-primary me-2"></i>Adicionar Contato
                </h1>
                <p class="text-muted small mb-0">Insira manualmente um novo lead na sua base de mineração.</p>
            </div>
            <a href="{{ route('contacts.index') }}" class="btn btn-outline-secondary btn-sm shadow-sm">
                <i class="bi bi-arrow-left"></i> Voltar para a Lista
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8">
                
                @includeif('partials.errors')

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-0 py-3">
                        <span class="fw-bold text-muted text-uppercase small">Formulário de Cadastro</span>
                    </div>
                    
                    <div class="card-body p-4 pt-0">
                        <form method="POST" action="{{ route('contacts.store') }}" role="form" enctype="multipart/form-data">
                            @csrf

                            @include('contact.form')

                        </form>
                    </div>
                </div>
                
                <div class="mt-4 p-3 bg-light rounded-3 border-start border-primary border-4">
                    <div class="d-flex">
                        <i class="bi bi-lightbulb text-primary me-2 fs-5"></i>
                        <small class="text-dark">
                            <strong>Dica de Especialista:</strong> Certifique-se de que o número de telefone inclua o código do país (DDI) e o código de área (DDD) para garantir a entrega via WhatsApp.
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection