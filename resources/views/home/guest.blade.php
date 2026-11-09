@extends('layouts.app-master')

@section('content')
@php
    $channels = [['bi-whatsapp', 'WhatsApp'], ['bi-telegram', 'Telegram'], ['bi-instagram', 'Instagram'], ['bi-facebook', 'Facebook'], ['bi-tiktok', 'TikTok'], ['bi-envelope-at', 'E-mail']];
    $groups = [
        ['bi-chat-dots', __('One inbox for every channel'), __('Your team answers everything in one place, with nothing falling through the cracks.'),
            [__('WhatsApp, Telegram, Instagram, Facebook, TikTok and e-mail together'), __('Assign, mention teammates and keep internal notes'), __('Snooze a conversation and it comes back on time'), __('Answer-time alerts (SLA) and satisfaction survey (CSAT)'), __('Quick replies, templates and a knowledge base inside the chat')]],
        ['bi-kanban', __('Sales funnel that closes deals'), __('See every opportunity, know what to do next and stop losing money in the gaps.'),
            [__('Kanban funnel with products, proposals and companies'), __('Checklists per stage so nobody skips a step'), __('Alerts for stalled deals and followers on every deal'), __('Win/loss analysis, conversion per stage and sales velocity'), __('Subscriptions with MRR/ARR and renewal reminders')]],
        ['bi-robot', __('Automation and AI that work for you'), __('Let the routine run by itself and keep your people for the conversations that matter.'),
            [__('AI agents that answer, qualify and recommend the next step'), __('Typebot flows with human handoff in one click'), __('Automations: when something happens, send, tag or create a task'), __('Follow-up sequences that stop when the customer replies'), __('Birthday messages and campaigns to segmented audiences')]],
        ['bi-people', __('Know every customer'), __('A complete picture of each person, from the first message to the renewal.'),
            [__('Timeline with messages, deals, tasks and calls'), __('Lifecycle: lead, qualified, opportunity, customer'), __('Log calls, meetings and visits'), __('Duplicates merge, bulk actions, import and export'), __('Online booking page for meetings')]],
        ['bi-diagram-3', __('Team, control and growth'), __('Give each person the right access and see how the operation is performing.'),
            [__('Owners, managers and agents with their own permissions'), __('Reports per person, source and period'), __('Audit log of who did what'), __('Plans, trial and billing already built in'), __('Several WhatsApp numbers with fair sending queue')]],
        ['bi-shield-check', __('Safe, open and compliant'), __('Your data stays yours, and everything connects to the rest of your stack.'),
            [__('LGPD: opt-out, consent history, data download and erasure'), __('Signed outbound webhooks to connect your stack'), __('Self-hosted: your server, your database'), __('Backups and hardened delivery'), __('Available in Portuguese, English and Spanish')]],
    ];
    $steps = [[__('Create your account'), __('Sign up in a minute and pick a plan. The free plan has no card.')], [__('Connect your channels'), __('Scan the WhatsApp QR code and add Instagram, Telegram or e-mail.')], [__('Let the system work'), __('Turn on the AI agent, the automations and the funnel and watch the results.')]];
    $faq = [
        [__('Do I need to install anything?'), __('No. Everything runs in the browser. For your own server there is a one-command installer.')],
        [__('Can my whole team use it?'), __('Yes. Add managers and agents, each one with the permissions you choose.')],
        [__('Is there a free plan?'), __('Yes. Start free and upgrade when you grow. Paid plans may include a free trial.')],
        [__('What about my customers\' data?'), __('It stays in your account. You can export it, and a customer can ask for it to be erased in one click.')],
    ];
@endphp
<div class="container py-5">
    @include('layouts.partials.messages')

    <div class="row align-items-center mb-5 pb-lg-4">
        <div class="col-lg-7 text-start">
            <div class="d-inline-flex align-items-center badge bg-dark border border-warning text-warning mb-3 px-3 py-2 rounded-pill">
                <span class="spinner-grow spinner-grow-sm me-2" role="status"></span>
                <small class="fw-bold text-uppercase">{{ __('CRM + WhatsApp + AI in one place') }}</small>
            </div>

            <h1 class="display-3 fw-bold text-white mb-3">
                {{ __('Answer faster, sell more,') }} <span class="text-warning">{{ __('lose no customer.') }}</span>
            </h1>

            <p class="lead text-white-50 mb-4 fs-4" style="max-width: 650px;">
                {{ __('The complete CRM for teams that sell and support on WhatsApp and other channels: inbox, sales funnel, AI agents, automations and campaigns working together.') }}
            </p>

            <div class="d-flex flex-column align-items-start gap-3">
                <div class="d-flex gap-3 flex-wrap">
                    @guest
                    <a href="{{ route('register.perform') }}" class="btn btn-warning btn-lg px-5 fw-bold shadow-lg py-3">
                        <i class="bi bi-rocket-takeoff me-2"></i>{{ __('START FREE') }}
                    </a>

                    <a href="{{ route('login.perform') }}" class="btn btn-outline-light btn-lg px-4 py-3">
                        {{ __('I ALREADY HAVE AN ACCOUNT') }}
                    </a>
                    @endguest

                    @auth
                    <div class="card bg-warning text-dark p-3 shadow-lg border-0">
                        <h5 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill me-2"></i>{{ __('Account created successfully!') }}</h5>
                        <p class="small mb-2">{{ __('To unlock the sending console, click the button below:') }}</p>
                        <a href="https://wa.me/5595981115965?text=Hi!+I+just+registered+on+Sender+with+username+{{ auth()->user()->username }}+and+would+like+to+request+access+activation."
                            target="_blank" class="btn btn-dark fw-bold w-100 py-2">
                            <i class="bi bi-whatsapp me-2"></i>{{ __('REQUEST ACTIVATION NOW') }}
                        </a>
                    </div>
                    @endauth
                </div>
                <div class="d-flex flex-wrap gap-3 text-white-50 small">
                    <span><i class="bi bi-check2-circle text-warning me-1"></i>{{ __('No credit card to start') }}</span>
                    <span><i class="bi bi-check2-circle text-warning me-1"></i>{{ __('Ready in minutes') }}</span>
                    <span><i class="bi bi-check2-circle text-warning me-1"></i>{{ __('Portuguese, English and Spanish') }}</span>
                </div>
            </div>
        </div>

        <div class="col-lg-5 d-none d-lg-block text-center">
            <div class="position-relative hero-art">
                <div class="blob-glow"></div>
                <img src="{{ asset('images/logo.jpg') }}" alt="{{ __('Logo Sender') }}" class="img-fluid floating-logo" width="100%">
                <span class="float-chip chip-a"><i class="bi bi-chat-dots-fill text-warning me-1"></i>{{ __('New message') }}</span>
                <span class="float-chip chip-b"><i class="bi bi-trophy-fill text-warning me-1"></i>{{ __('Deal won!') }}</span>
                <span class="float-chip chip-c"><i class="bi bi-robot text-warning me-1"></i>{{ __('AI agent replied') }}</span>
            </div>
        </div>
    </div>

    <div class="channel-strip text-center py-4 mb-5">
        <p class="text-white-50 small text-uppercase mb-3" style="letter-spacing:2px">{{ __('All your channels in one inbox') }}</p>
        <div class="d-flex flex-wrap justify-content-center gap-4 fs-5">
            @foreach ($channels as [$icon, $name])
                <span class="text-white"><i class="bi {{ $icon }} text-warning me-2"></i>{{ $name }}</span>
            @endforeach
        </div>
    </div>

    <div class="text-center mb-4">
        <h2 class="text-white fw-bold display-6">{{ __('Everything your operation needs, in one system') }}</h2>
        <p class="text-white-50">{{ __('Stop paying for five tools that do not talk to each other.') }}</p>
    </div>
    <div class="row g-4 mb-5">
        @foreach ($groups as [$icon, $title, $sub, $items])
            <div class="col-lg-4 col-md-6">
                <div class="card bg-dark border-secondary h-100 p-4 hover-lift">
                    <div class="d-flex align-items-center mb-2">
                        <span class="feature-icon me-3"><i class="bi {{ $icon }}"></i></span>
                        <h5 class="text-white fw-bold mb-0">{{ $title }}</h5>
                    </div>
                    <p class="text-white-50 small">{{ $sub }}</p>
                    <ul class="list-unstyled small mb-0 text-light">
                        @foreach ($items as $it)<li class="mb-2 d-flex"><i class="bi bi-check2-circle text-warning me-2"></i><span>{{ $it }}</span></li>@endforeach
                    </ul>
                </div>
            </div>
        @endforeach
    </div>

    <div class="how-it-works rounded-4 p-4 p-lg-5 mb-5">
        <h2 class="text-white fw-bold text-center mb-4">{{ __('Up and running in 3 steps') }}</h2>
        <div class="row g-4">
            @foreach ($steps as $i => [$t, $d])
                <div class="col-md-4 text-center">
                    <div class="step-number mx-auto mb-3">{{ $i + 1 }}</div>
                    <h5 class="text-white fw-bold">{{ $t }}</h5>
                    <p class="text-white-50 small mb-0">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </div>

    @if(isset($plans) && $plans->isNotEmpty())
    <div class="mt-5 pt-4">
        <div class="text-center mb-4">
            <h2 class="text-white fw-bold">{{ __('Plans') }}</h2>
            <p class="text-muted">{{ __('Start free. Upgrade when you grow.') }}</p>
        </div>
        <div class="row g-4">
            @foreach($plans as $plan)
                <div class="col-xl-3 col-md-6">
                    <div class="card bg-dark border-secondary h-100 p-4 hover-lift {{ $plan->slug === 'pro' ? 'border-warning' : '' }}">
                        <h5 class="text-white fw-bold mb-1">{{ $plan->name }}
                            @if($plan->slug === 'pro')<span class="badge bg-warning text-dark ms-1">{{ __('Most popular') }}</span>@endif
                        </h5>
                        <div class="my-2 text-white">
                            <span class="fs-2 fw-bold">{{ $plan->isFree() ? __('Free') : 'R$ ' . number_format($plan->price_cents / 100, 0, ',', '.') }}</span>
                            @unless($plan->isFree())<span class="text-muted small">/ {{ __('month') }}</span>@endunless
                        </div>
                        <ul class="list-unstyled small flex-grow-1 text-light">
                            @foreach(\App\Models\Plan::limitLabels() as $key => $label)
                                <li class="mb-1"><i class="bi bi-check2 text-warning me-1"></i><strong>{{ $plan->limit($key) === null ? __('Unlimited') : number_format($plan->limit($key), 0, ',', '.') }}</strong> {{ strtolower($label) }}</li>
                            @endforeach
                            @foreach(\App\Models\Plan::featureLabels() as $key => $label)
                                <li class="mb-1 {{ $plan->hasFeature($key) ? '' : 'text-muted' }}"><i class="bi {{ $plan->hasFeature($key) ? 'bi-check2 text-warning' : 'bi-x text-secondary' }} me-1"></i>{{ $label }}</li>
                            @endforeach
                        </ul>
                        <a href="{{ route('register.show') }}" class="btn {{ $plan->slug === 'pro' ? 'btn-warning' : 'btn-outline-light' }} mt-2">
                            {{ $plan->isFree() ? __('Start free') : ($plan->trial_days > 0 ? __('Try :n days free', ['n' => $plan->trial_days]) : __('Subscribe')) }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif
</div>

<div class="container pb-5">
    <div class="mt-5">
        <h2 class="text-white fw-bold text-center mb-4">{{ __('Frequently asked questions') }}</h2>
        <div class="accordion accordion-flush faq mx-auto" id="faq" style="max-width:820px">
            @foreach ($faq as $i => [$q, $a])
                <div class="accordion-item bg-transparent border-secondary">
                    <h3 class="accordion-header"><button class="accordion-button collapsed bg-transparent text-white shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#faq{{ $i }}">{{ $q }}</button></h3>
                    <div id="faq{{ $i }}" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body text-white-50">{{ $a }}</div></div>
                </div>
            @endforeach
        </div>
    </div>

    @guest
    <div class="final-cta text-center rounded-4 p-5 mt-5">
        <h2 class="fw-bold text-dark mb-2">{{ __('Ready to stop losing customers?') }}</h2>
        <p class="text-dark mb-4">{{ __('Create your free account and have your first conversation organized today.') }}</p>
        <a href="{{ route('register.perform') }}" class="btn btn-dark btn-lg px-5 fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>{{ __('START FREE') }}</a>
    </div>
    @endguest
</div>

<a href="https://wa.me/5595981115965?text=Hi!+I+have+questions+about+Sender+and+would+like+to+speak+with+someone."
    target="_blank" class="whatsapp-balloon shadow-lg">
    <div class="balloon-content">
        <div class="online-indicator"><span class="blink-dot"></span></div>
        <i class="bi bi-whatsapp"></i>
    </div>
    <div class="balloon-text-wrapper">
        <span class="top-text">{{ __('Questions?') }}</span>
        <span class="main-text">{{ __('Talk to us') }}</span>
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

    .float-chip { position: absolute; background: rgba(20,20,20,.92); border: 1px solid rgba(255,193,7,.35); color: #fff; padding: 8px 14px; border-radius: 50px; font-size: .85rem; font-weight: 600; box-shadow: 0 10px 30px rgba(0,0,0,.5); animation: float 6s ease-in-out infinite; }
    .chip-a { top: 8%; left: -4%; } .chip-b { bottom: 14%; right: -2%; animation-delay: -2s; } .chip-c { bottom: 40%; left: -10%; animation-delay: -4s; }
    .channel-strip { border-top: 1px solid rgba(255,255,255,.08); border-bottom: 1px solid rgba(255,255,255,.08); }
    .feature-icon { width: 46px; height: 46px; border-radius: 12px; background: rgba(255,193,7,.12); color: #ffc107; display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; flex: none; }
    .how-it-works { background: linear-gradient(135deg, rgba(255,193,7,.10), rgba(255,255,255,.02)); border: 1px solid rgba(255,193,7,.2); }
    .step-number { width: 54px; height: 54px; border-radius: 50%; background: #ffc107; color: #111; font-weight: 800; font-size: 1.5rem; display: flex; align-items: center; justify-content: center; }
    .faq .accordion-button::after { filter: invert(1); }
    .final-cta { background: linear-gradient(135deg, #ffc107, #ff9800); }
    @media (max-width: 575px) { .display-3 { font-size: 2.2rem; } }
</style>
@endsection