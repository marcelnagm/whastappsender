@extends('layouts.app-master')

@section('admin_title')
{{ config('settings.url_route')." ".__('Management')}}
@endsection

@section('content')
<div class="header bg-gradient-primary pb-8 pt-5 pt-md-8">
</div>

<div class="container-fluid mt--7">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="card shadow border-0 overflow-hidden">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-md-7 p-4 p-lg-5 bg-white">
                            <div class="d-flex align-items-center mb-4">
                                <div class="icon icon-shape bg-success text-white rounded-circle shadow me-3">
                                    <i class="fab fa-whatsapp"></i>
                                </div>
                                <h2 class="mb-0 fw-bold">Conectar WhatsApp</h2>
                            </div>
                            
                            <h4 class="text-muted mb-4">Siga as etapas para parear seu dispositivo:</h4>
                            
                            <ul class="list-unstyled mb-5">
                                <li class="mb-4 d-flex align-items-start">
                                    <span class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center" style="width:28px; height:28px;">1</span>
                                    <div class="text-dark">Abra o <strong>WhatsApp</strong> no seu celular.</div>
                                </li>
                                <li class="mb-4 d-flex align-items-start">
                                    <span class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center" style="width:28px; height:28px;">2</span>
                                    <div>
                                        Toque em <strong>Mais opções</strong> ( <i class="fas fa-ellipsis-v small"></i> ) ou 
                                        <strong>Configurações</strong> ( <i class="fas fa-cog small"></i> ).
                                    </div>
                                </li>
                                <li class="mb-4 d-flex align-items-start">
                                    <span class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center" style="width:28px; height:28px;">3</span>
                                    <div>Selecione <strong>Aparelhos conectados</strong> e toque em <strong>Conectar um aparelho</strong>.</div>
                                </li>
                                <li class="d-flex align-items-start">
                                    <span class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center" style="width:28px; height:28px;">4</span>
                                    <div>Aponte a câmera para esta tela para capturar o <strong>QR Code</strong>.</div>
                                </li>
                            </ul>

                            <div class="alert alert-secondary border-0 shadow-none rounded-3">
                                <div class="d-flex align-items-center text-dark small">
                                    <i class="fas fa-info-circle text-info me-2 fs-4"></i>
                                    <span><strong>Importante:</strong> Mantenha o aparelho conectado à internet para que os mineradores e disparadores funcionem em tempo real.</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-5 bg-light d-flex align-items-center justify-content-center border-start py-5">
                            <div id="qr_section" class="text-center px-4">
                                <div class="mb-4 bg-white shadow-sm d-inline-block p-3 rounded-lg border position-relative" style="border-radius: 15px;">
                                    <div id="qr" class="d-flex align-items-center justify-content-center" style="min-height: 260px; min-width: 260px;">
                                        @if(is_array($res) && isset($res['base64']))
                                            <img src="{{ $res['base64'] }}" class="img-fluid" alt="QR Code" id="current_qr_img">
                                        @else
                                            <div class="text-center text-muted py-5" id="qr_placeholder">
                                                <i class="fas fa-sync fa-spin fa-3x mb-3 text-lighter"></i>
                                                <p class="small fw-bold">Iniciando sessão...</p>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="button" onclick="getQR();" class="btn btn-primary btn-lg shadow-sm">
                                        <i class="fas fa-sync-alt me-2"></i> Atualizar QR Code
                                    </button>
                                </div>
                                <p class="text-muted mt-3 x-small"><i class="fas fa-lock me-1"></i> Conexão criptografada ponta a ponta.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .x-small { font-size: 0.75rem; }
    .text-lighter { color: #e9ecef; }
    #qr img { max-width: 260px; height: auto; display: block; }
    .badge-primary { background-color: #5e72e4 !important; }
    @media (max-width: 768px) {
        .border-start { border-left: none !important; border-top: 1px solid #dee2e6 !important; }
    }
</style>
@endsection

@section('js')
<script>
    /**
     * Variáveis de Ambiente injetadas via Blade
     */
    const protocol = "{{ env('WHATSAPP_PROTOCOL', 'http') }}";
    const hostname = "{{ env('WHATSAPP_URL', 'localhost') }}";
    const port     = "{{ env('WHATSAPP_PORT', '8080') }}";
    const apiKey   = "{{ env('WHATSAPP_APIKEY') }}";
    const session  = "{{ auth()->user()->contact() }}"; // Identificador da instância

    const baseUrl = `${protocol}://${hostname}:${port}`;
    let qrcod_lido = false;

    $(document).ready(function() {
        // 1. Checa se a sessão já está conectada ao carregar a página
        checkSessionStatus();
    });

    /**
     * Verifica o status da sessão na API
     */
    function checkSessionStatus() {
        $.ajax({
            type: 'GET',
            url: `${baseUrl}/sessions/${session}/status`,
            success: function(response) {
                console.log("Sessão Ativa:", response);
                // Se estiver conectado, podemos recarregar para mostrar o status de sucesso
                // ou redirecionar. Aqui mantemos sua lógica original de reload.
                location.reload(true);
            },
            error: function() {
                console.log("Sessão inativa ou inexistente. Gerando QR...");
                qrcod_lido = false;
                getQR();
            }
        });
    }

    /**
     * Solicita um novo QR Code para a instância
     */
    function getQR() {
        const qrContainer = $("#qr");
        
        // Feedback visual de carregamento
        if (!qrContainer.find('.fa-spin').length) {
            qrContainer.html('<div class="text-center text-muted"><i class="fas fa-sync fa-spin fa-2x mb-2"></i><p class="small">Gerando...</p></div>');
        }

        $.ajax({
            url: `${baseUrl}/instance/connect/${session}`,
            type: 'GET',
            headers: { "apikey": apiKey },
            contentType: 'application/json',
            success: function(data) {
                if (data && data.base64) {
                    const img = new Image();
                    img.src = data.base64;
                    img.className = "img-fluid animate__animated animate__fadeIn";
                    qrContainer.html(img);
                } else {
                    qrContainer.html('<p class="text-danger small">Erro ao gerar código. Tente novamente.</p>');
                }
            },
            error: function(err) {
                console.error("Erro na API:", err);
                qrContainer.html('<p class="text-danger small">Serviço Indisponível</p>');
            }
        });
    }

    /**
     * Remove a sessão (Logout da instância)
     */
    function remove_session() {
        if (!confirm("Deseja realmente desconectar este WhatsApp?")) return;

        $.ajax({
            url: `${baseUrl}/sessions/${session}`,
            type: 'DELETE',
            success: function(data) {
                location.reload(true);
            }
        });
    }
</script>
@endsection