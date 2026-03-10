@extends('layouts.app-master')

@section('content')
    <div class="py-4">
        @include('layouts.partials.messages')

        @auth
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="bi bi-speedometer2"></i> Painel de Controle</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <button type="button" class="btn btn-sm btn-outline-primary me-2">
                    <i class="bi bi-download"></i> Exportar Leads
                </button>
                <button type="button" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle"></i> Nova Campanha
                </button>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-primary bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-people-fill fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Total Minerado</h6>
                                <h3 class="fw-bold mb-0">{{$contact}}</h3> </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-success bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-whatsapp fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Instâncias Ativas</h6>
                                <h3 class="fw-bold mb-0">1</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-info bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-send-check fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Lidos (Hoje)</h6>
                                <h3 class="fw-bold mb-0">{{$read}}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-danger bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-exclamation-triangle fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Falhas Criticas</h6>
                                <h3 class="fw-bold mb-0">{{$error}}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-white py-3">
                        <h5 class="card-title mb-0 fw-bold">Atividade de Mineração (Lotes)</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Monitoramento em tempo real dos seus 10 processos paralelos.</p>
                        @for($i=1; $i<=3; $i++)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="small fw-semibold">Processo #{{$i}} (Lote 00{{$i}})</span>
                                <span class="small text-muted">75%</span>
                            </div>
                            <div class="progress" style="height: 8px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 75%"></div>
                            </div>
                        </div>
                        @endfor
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-white py-3 text-center">
                        <h5 class="card-title mb-0 fw-bold">Suporte & Ativação</h5>
                    </div>
                    <div class="card-body text-center py-4">
                        <i class="bi bi-headset fs-1 text-primary mb-3"></i>
                        <p>Dúvidas ou Upgrade de Licença?</p>
                        <a href="https://wa.me/5595981110695" target="_blank" class="btn btn-success w-100">
                            <i class="bi bi-whatsapp"></i> Chamar Suporte
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endauth

        @guest
        <div class="text-center py-5">
            <h1 class="display-4 fw-bold">Mining <span class="text-primary">System</span></h1>
            <p class="lead mb-4">Acesse a plataforma para visualizar os dados restritos da mineração.</p>
            <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                <a href="{{ route('login.perform') }}" class="btn btn-primary btn-lg px-4 gap-3">Entrar</a>
                <a href="mailto:marcel.nagm@gmail.com" class="btn btn-outline-secondary btn-lg px-4">Solicitar Acesso</a>
            </div>
        </div>
        @endguest
    </div>
@endsection