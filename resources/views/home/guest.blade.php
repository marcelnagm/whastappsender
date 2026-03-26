@extends('layouts.app-master')

@section('content')
<div class="container py-5">
    @include('layouts.partials.messages')

    <div class="row align-items-center mb-5 pb-lg-5">
        <div class="col-lg-7 text-start">
            <div class="d-inline-flex align-items-center badge bg-dark border border-warning text-warning mb-3 px-3 py-2 rounded-pill">
                <span class="spinner-grow spinner-grow-sm me-2" role="status"></span>
                <small class="fw-bold text-uppercase">Tecnologia Proprietária Ativa</small>
            </div>

            <h1 class="display-2 fw-bold text-white mb-3">
                Comunicação em Massa com <br><span class="text-warning">Inteligência Preditiva.</span>
            </h1>

            <p class="lead text-white-50 mb-4 fs-4" style="max-width: 650px;">
                Pare de enviar mensagens e comece a gerir fluxos de alta performance. Realize seu cadastro e solicite a ativação da sua licença exclusiva.
            </p>

            <div class="d-flex flex-column align-items-start gap-3">
                <div class="d-flex gap-3 flex-wrap">
                    @guest
                    <a href="{{ route('register.perform') }}" class="btn btn-warning btn-lg px-5 fw-bold shadow-lg py-3">
                        <i class="bi bi-person-plus-fill me-2"></i>CRIAR MINHA CONTA
                    </a>

                    <a href="{{ route('login.perform') }}" class="btn btn-outline-light btn-lg px-4 py-3">
                        JÁ SOU PARCEIRO
                    </a>
                    @endguest

                    @auth
                    <div class="card bg-warning text-dark p-3 shadow-lg border-0">
                        <h5 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill me-2"></i>Conta Criada com Sucesso!</h5>
                        <p class="small mb-2">Para liberar o console de disparos, clique no botão abaixo:</p>
                        <a href="https://wa.me/5595981115965?text=Olá!+Acabei+de+me+cadastrar+no+Sender+com+o+usuário+{{ auth()->user()->username }}+e+quero+solicitar+a+ativação+do+meu+acesso."
                            target="_blank" class="btn btn-dark fw-bold w-100 py-2">
                            <i class="bi bi-whatsapp me-2"></i>SOLICITAR ATIVAÇÃO AGORA
                        </a>
                    </div>
                    @endauth
                </div>
            </div>

            <p class="text-muted mt-4 small">
                <i class="bi bi-info-circle me-1"></i> Acesso ao Dashboard liberado apenas após validação da licença.
            </p>
        </div>

        <div class="col-lg-5 d-none d-lg-block text-center">
            <div class="position-relative">
                <div class="blob-glow"></div>
                <img src="{{ asset('images/logo-no-bg.png') }}" alt="Logo Sender" class="img-fluid floating-logo" width="100%">
            </div>
        </div>
    </div>

    <div class="row g-4 mt-5">
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-robot text-warning fs-1 me-3"></i>
                    <h5 class="text-white fw-bold mb-0">IA de Balanceamento</h5>
                </div>
                <p class="text-muted small">Algoritmos que analisam a saúde da operação e distribuem a carga para mimetizar o comportamento humano.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-graph-up-arrow text-warning fs-1 me-3"></i>
                    <h5 class="text-white fw-bold mb-0">Escala Estratégica</h5>
                </div>
                <p class="text-muted small">Estrutura preparada para bases massivas e processamento em camadas, garantindo estabilidade absoluta.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-patch-check text-warning fs-1 me-3"></i>
                    <h5 class="text-white fw-bold mb-0">Entrega Blindada</h5>
                </div>
                <p class="text-muted small">Protocolos de segurança e roteamento inteligente para assegurar que a sua comunicação chegue ao destino.</p>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-2 mb-5">
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-pie-chart text-warning fs-1 me-3"></i>
                    <h5 class="text-white fw-bold mb-0">Analytics Real-Time</h5>
                </div>
                <p class="text-muted small">Monitore taxas de entrega através de um console centralizado, permitindo ajustes imediatos na sua estratégia.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-cloud-arrow-up text-warning fs-1 me-3"></i>
                    <h5 class="text-white fw-bold mb-0">Proteção de Ativos</h5>
                </div>
                <p class="text-muted small">Backup cíclico de bases de dados e histórico em camadas redundantes, blindando o seu maior patrimônio.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                <div class="d-flex align-items-center mb-3">
                    <i class="bi bi-gear-wide-connected text-warning fs-1 me-3"></i>
                    <h5 class="text-white fw-bold mb-0">Integração Total</h5>
                </div>
                <p class="text-muted small">Conecte o sistema ao seu ecossistema via Webhooks, automatizando o fluxo entre o WhatsApp e seu CRM.</p>
            </div>
        </div>
    </div>
</div>

<a href="https://wa.me/5595981115965?text=Olá!+Tenho+dúvidas+sobre+o+Sender+e+gostaria+de+falar+com+um+consultor."
    target="_blank" class="whatsapp-balloon shadow-lg">
    <div class="balloon-content">
        <div class="online-indicator"><span class="blink-dot"></span></div>
        <i class="bi bi-whatsapp"></i>
    </div>
    <div class="balloon-text-wrapper">
        <span class="top-text">Dúvidas?</span>
        <span class="main-text">Fale com a gente</span>
    </div>
</a>

<style>
    body {
        background: #0a0a0a;
        color: #fff;
        font-family: 'Inter', sans-serif;
        overflow-x: hidden;
    }

    .blob-glow {
        position: absolute;
        top: 50%;
        left: 50%;
        width: 350px;
        height: 350px;
        background: rgba(255, 193, 7, 0.08);
        filter: blur(120px);
        transform: translate(-50%, -50%);
        border-radius: 50%;
    }

    .floating-logo {
        max-height: 380px;
        animation: float 5s ease-in-out infinite;
        filter: drop-shadow(0 0 40px rgba(255, 193, 7, 0.15));
    }

    @keyframes float {

        0%,
        100% {
            transform: translateY(0);
        }

        50% {
            transform: translateY(-15px);
        }
    }

    .hover-lift {
        transition: all 0.4s ease;
        background: rgba(255, 255, 255, 0.02) !important;
        border: 1px solid rgba(255, 255, 255, 0.08) !important;
    }

    .hover-lift:hover {
        transform: translateY(-8px);
        border-color: #ffc107 !important;
        background: rgba(255, 193, 7, 0.05) !important;
    }

    .whatsapp-balloon {
        position: fixed;
        bottom: 30px;
        right: 30px;
        background: #25D366;
        color: #fff !important;
        text-decoration: none;
        display: flex;
        align-items: center;
        padding: 10px 22px;
        border-radius: 50px;
        z-index: 9999;
        box-shadow: 0 10px 25px rgba(37, 211, 102, 0.4);
        border: 2px solid rgba(255, 255, 255, 0.1);
    }

    .balloon-content {
        font-size: 28px;
        margin-right: 12px;
        display: flex;
        align-items: center;
        position: relative;
    }

    .balloon-text-wrapper {
        display: flex;
        flex-direction: column;
        line-height: 1.2;
    }

    .top-text {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        opacity: 0.9;
    }

    .main-text {
        font-size: 1rem;
        font-weight: 700;
        white-space: nowrap;
    }

    .online-indicator {
        position: absolute;
        top: -2px;
        right: -5px;
        width: 12px;
        height: 12px;
        background: #fff;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .blink-dot {
        width: 8px;
        height: 8px;
        background: #00e676;
        border-radius: 50%;
        animation: pulse-green 2s infinite;
    }

    @keyframes pulse-green {
        0% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(0, 230, 118, 0.7);
        }

        70% {
            transform: scale(1);
            box-shadow: 0 0 0 10px rgba(0, 230, 118, 0);
        }

        100% {
            transform: scale(0.95);
            box-shadow: 0 0 0 0 rgba(0, 230, 118, 0);
        }
    }
</style>
@endsection