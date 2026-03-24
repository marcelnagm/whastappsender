@extends('layouts.app-master')

@section('content')
<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card bg-white border shadow-sm">
                <div class="card-header bg-white border-bottom-0 pt-4">
                    <h4 class="fw-bold text-dark">Configurar Instância</h4>
                </div>
                <div class="card-body">
                    <form action="{{ route('instances.store') }}" method="POST">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nome da Conexão</label>
                            <input type="text" name="name" class="form-control border" placeholder="Ex: Suporte Vendas" required>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold">Telefone (Número)</label>
                            <input type="text" name="phone" class="form-control border" placeholder="5511999999999" required>
                            <div class="form-text text-muted">Use apenas números com DDD (ex: 5511...).</div>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary fw-bold mb-2">Criar Instância</button>
                            <a href="{{ route('instances.index') }}" class="btn btn-link text-muted">Cancelar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection