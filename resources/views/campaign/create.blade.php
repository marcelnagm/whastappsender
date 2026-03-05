@extends('layouts.app-master')

@section('template_title')
    Nova Campanha - Mining System
@endsection

@section('content')
    <div class="container-fluid">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-rocket-takeoff text-primary me-2"></i>Lançar Nova Campanha
                </h1>
                <p class="text-muted small mb-0">Defina o nome e os parâmetros iniciais da sua estratégia de disparos.</p>
            </div>
            <a href="{{ route('campaigns.index') }}" class="btn btn-outline-secondary btn-sm shadow-sm">
                <i class="bi bi-arrow-left"></i> Voltar para Campanhas
            </a>
        </div>

        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                
                @includeif('partials.errors')

                <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                    <div class="card-header bg-white border-0 py-3 border-bottom">
                        <span class="fw-bold text-muted text-uppercase small">Configurações Básicas</span>
                    </div>
                    
                    <div class="card-body p-4">
                        <form method="POST" action="{{ route('campaigns.store') }}" role="form" enctype="multipart/form-data">
                            @csrf

                            @include('campaign.form')

                            {{-- Nota: O botão de submit já deve estar dentro do campaign.form 
                                 Se não estiver, adicione aqui um botão condizente --}}
                        </form>
                    </div>
                </div>

                <div class="card bg-gradient-light border-0 mt-4 rounded-4 shadow-sm">
                    <div class="card-body">
                        <div class="d-flex">
                            <div class="icon-shape bg-white text-primary rounded-circle shadow-sm p-3 me-3">
                                <i class="bi bi-info-circle fs-4"></i>
                            </div>
                            <div>
                                <h6 class="fw-bold mb-1">Próximos Passos:</h6>
                                <p class="text-muted small mb-0">
                                    Após criar a campanha, você poderá adicionar os <strong>Itens da Campanha</strong> (mensagens/mídias) e vincular a lista de <strong>Contatos</strong> para o envio.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection