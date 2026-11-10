@extends('layouts.app-master')

@section('own_header', '1')

@section('content')
@php
    $channels = [['bi-whatsapp', 'WhatsApp'], ['bi-telegram', 'Telegram'], ['bi-instagram', 'Instagram'], ['bi-facebook', 'Facebook'], ['bi-tiktok', 'TikTok'], ['bi-envelope-at', 'E-mail'], ['bi-window-stack', __('Website chat')]];

    $facts = [
        ['7', __('channels in one inbox')],
        ['AI', __('that learns your business')],
        ['3', __('languages: PT, EN, ES')],
        ['100%', __('yours: self-host on your server')],
    ];

    // The whole product, by theme. Each tab: icon, title, one-line promise, items.
    $groups = [
        ['bi-chat-dots', __('Inbox and channels'), __('Your team answers everything in one place, with nothing falling through the cracks.'), [
            __('WhatsApp, Telegram, Instagram, Facebook, TikTok, e-mail and website chat together'),
            __('Website chat widget: one line of code and visitors join your inbox'),
            __('Shared mailboxes with a default owner and a signature'),
            __('Assign, mention teammates and keep internal notes'),
            __('Snooze a conversation and it comes back on time'),
            __('Opening hours, answer-time alerts and satisfaction surveys'),
            __('Quick replies and saved views for the filters you use every day'),
        ]],
        ['bi-kanban', __('Sales and renewals'), __('See every opportunity, know what to do next and stop losing money in the gaps.'), [
            __('Kanban funnel with products, proposals and companies'),
            __('Checklists per stage so nobody skips a step'),
            __('Alerts for stalled deals and followers on every deal'),
            __('Forecast, win/loss analysis and conversion per stage'),
            __('Recurring revenue and renewals with reminders before they expire'),
            __('Follow-up tasks with due time and owner'),
            __('Bookings with a public page for your customers'),
        ]],
        ['bi-robot', __('AI that knows your business'), __('Teach the AI once and it answers, qualifies and reports like someone from your team.'), [
            __('AI agents that answer, qualify and suggest the next step'),
            __('Teach it with FAQs, texts, files, your website and your product catalog'),
            __('Answers from what you taught and says "I will check" instead of inventing'),
            __('Business insights: a report with key figures, findings and ranked actions'),
            __('Reports delivered by e-mail and WhatsApp every week or month'),
            __('Thumbs up/down on AI answers to improve it'),
            __('Typebot flows with human handoff in one click'),
        ]],
        ['bi-diagram-3', __('Automation and campaigns'), __('Let the routine run by itself and keep your people for the conversations that matter.'), [
            __('Automations: when something happens, send, tag or create a task'),
            __('Follow-up sequences that stop when the customer replies'),
            __('Campaigns with approval, scheduling and plan priority'),
            __('Forms and segments that feed your audience'),
            __('Birthday reminders and renewal notices'),
            __('Signed outbound webhooks to connect your stack'),
            __('Opt-out and consent kept on every channel'),
        ]],
        ['bi-people', __('Know every customer'), __('A complete picture of each person, from the first message to the renewal.'), [
            __('Timeline with messages, deals, tasks and calls'),
            __('Lifecycle: lead, qualified, opportunity, customer'),
            __('Log calls, meetings and visits'),
            __('Companies, custom fields and engagement score'),
            __('Duplicates merge, bulk actions, import and export'),
            __('Every channel of the same person unified in one record'),
            __('Customers can ask for their data to be exported or erased'),
        ]],
        ['bi-shield-check', __('Control and security'), __('Give each person the right access and run your operation with confidence.'), [
            __('Owners, managers and agents with their own permissions'),
            __('Audit log of who did what'),
            __('Reports per person, source and period'),
            __('Plans, trial and billing already built in'),
            __('Self-hosted: your server, your database, one-command installer'),
            __('Usage and cost of the AI visible, with limits per plan'),
            __('Backups and hardened delivery'),
        ]],
    ];

    $segments = [
        ['bi-bag-heart', __('Retail and e-commerce'), __('Best-selling products, average ticket and the questions customers ask about price and stock.')],
        ['bi-heart-pulse', __('Clinics and services'), __('Demand per service, scheduling flow and how fast you follow up.')],
        ['bi-house-door', __('Real estate'), __('Which source and which broker convert, and why deals are lost.')],
        ['bi-cloud-check', __('B2B and SaaS'), __('Pipeline value, win rate, MRR and renewals at risk.')],
        ['bi-mortarboard', __('Education and courses'), __('Enquiries per course, conversion to enrolment and why people give up.')],
        ['bi-cup-hot', __('Restaurants and delivery'), __('Peak demand, best sellers and answer speed.')],
    ];

    $steps = [
        [__('Create your account'), __('Sign up in a minute and pick a plan. The free plan has no card.')],
        [__('Connect your channels'), __('Scan the WhatsApp QR code and add Instagram, Telegram, e-mail or the website chat.')],
        [__('Teach the AI'), __('Paste your FAQ, point it to your site and import your products.')],
        [__('Sell and learn'), __('Work the inbox and the funnel, and read what the AI found about your business.')],
    ];

    $replaces = [__('Shared inbox'), __('CRM and pipeline'), __('Chatbot builder'), __('Bulk sender'), __('Website chat'), __('Reports and dashboards')];

    $faq = [
        [__('Do I need to install anything?'), __('No. Everything runs in the browser. For your own server there is a one-command installer.')],
        [__('Can my whole team use it?'), __('Yes. Add managers and agents, each one with the permissions you choose.')],
        [__('Is there a free plan?'), __('Yes. Start free and upgrade when you grow. Paid plans may include a free trial.')],
        [__('How does the AI learn about my business?'), __('You give it your FAQs, texts, files, website and products. It answers from that material and, when it does not know, says it will check instead of inventing. The numbers in the reports are computed by the system from your own data; the AI only interprets them.')],
        [__('Which AI providers can I use?'), __('You bring your own key from the providers you prefer. You can see the usage of the month and your plan can set limits.')],
        [__('Can I put the chat on my website?'), __('Yes. Create the widget, paste one line of code on your site and visitors talk to your team or to the AI inside the same inbox as your other channels.')],
        [__('What about my customers\' data?'), __('It stays in your account. You can export it, and a customer can ask for it to be erased in one click.')],
        [__('Can I host it on my own server?'), __('Yes. The system is self-hosted with Docker and a one-command installer, so your data stays on your infrastructure.')],
    ];
@endphp

<div class="lp">
    {{-- ───────── Header ───────── --}}
    <header class="lp-nav navbar navbar-expand-lg navbar-dark sticky-top">
        <a class="navbar-brand d-flex align-items-center" href="/">
            <img src="{{ asset('images/logo.jpg') }}" alt="{{ __('Logo Sender') }}" width="34" height="34" class="rounded-2 me-2">
            <span class="fw-bold">{{ config('app.name', 'Sender') }}</span>
        </a>
        <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#lpMenu" aria-controls="lpMenu" aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="lpMenu">
            <ul class="navbar-nav mx-lg-auto">
                <li class="nav-item"><a class="nav-link" href="#features">{{ __('Features') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="#ai">{{ __('AI') }}</a></li>
                <li class="nav-item"><a class="nav-link" href="#segments">{{ __('Who it is for') }}</a></li>
                @if (isset($plans) && $plans->isNotEmpty())<li class="nav-item"><a class="nav-link" href="#plans">{{ __('Plans') }}</a></li>@endif
                <li class="nav-item"><a class="nav-link" href="#faq">FAQ</a></li>
            </ul>
            <div class="d-flex align-items-center gap-2 mt-3 mt-lg-0">
                <div class="lp-lang">@include('layouts.partials.language-selector', ['variant' => 'inline'])</div>
                @guest
                    <a href="{{ route('login.perform') }}" class="btn btn-sm btn-outline-light px-3">{{ __('Login') }}</a>
                    <a href="{{ route('register.perform') }}" class="btn btn-sm btn-warning fw-semibold px-3">{{ __('Sign-up') }}</a>
                @endguest
                @auth
                    <a href="{{ route('logout.perform') }}" class="btn btn-sm btn-outline-light px-3">{{ __('Log out') }}</a>
                @endauth
            </div>
        </div>
    </header>

    @include('layouts.partials.messages')

    {{-- ───────── Hero ───────── --}}
    <section class="lp-hero row align-items-center g-5">
        <div class="col-lg-6">
            <span class="lp-eyebrow"><i class="bi bi-stars me-1"></i>{{ __('CRM + WhatsApp + AI in one place') }}</span>
            <h1 class="lp-h1">{{ __('Answer faster, sell more,') }} <span class="lp-accent">{{ __('lose no customer.') }}</span></h1>
            <p class="lp-lead">{{ __('The complete CRM for teams that sell and support on WhatsApp and other channels: inbox, sales funnel, AI that learns your business, automations and campaigns working together.') }}</p>

            @guest
                <div class="d-flex flex-wrap gap-3 mb-4">
                    <a href="{{ route('register.perform') }}" class="btn btn-warning btn-lg px-4 fw-bold lp-cta"><i class="bi bi-rocket-takeoff me-2"></i>{{ __('START FREE') }}</a>
                    <a href="#features" class="btn btn-outline-light btn-lg px-4">{{ __('See what is inside') }}</a>
                </div>
            @endguest
            @auth
                <div class="card bg-warning text-dark p-3 shadow-lg border-0 mb-4" style="max-width:460px">
                    <h5 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill me-2"></i>{{ __('Account created successfully!') }}</h5>
                    <p class="small mb-2">{{ __('To unlock the sending console, click the button below:') }}</p>
                    <a href="https://wa.me/5595981115965?text=Hi!+I+just+registered+on+Sender+with+username+{{ auth()->user()->username }}+and+would+like+to+request+access+activation."
                        target="_blank" rel="noopener" class="btn btn-dark fw-bold w-100 py-2"><i class="bi bi-whatsapp me-2"></i>{{ __('REQUEST ACTIVATION NOW') }}</a>
                </div>
            @endauth
            <ul class="lp-trust list-unstyled d-flex flex-wrap gap-x-4 mb-0">
                <li><i class="bi bi-check2-circle"></i>{{ __('No credit card to start') }}</li>
                <li><i class="bi bi-check2-circle"></i>{{ __('Ready in minutes') }}</li>
                <li><i class="bi bi-check2-circle"></i>{{ __('Portuguese, English and Spanish') }}</li>
            </ul>
        </div>

        {{-- Product preview: drawn with HTML so it is always sharp and loads instantly --}}
        <div class="col-lg-6">
            <div class="lp-window" role="img" aria-label="{{ __('Illustration of the shared inbox with an AI answer') }}">
                <div class="lp-window-bar"><i></i><i></i><i></i><span>{{ __('Chat') }}</span></div>
                <div class="lp-window-body">
                    <div class="lp-list">
                        <div class="lp-conv active"><span class="lp-av">M</span><div><b>Marina</b><small><i class="bi bi-whatsapp"></i> {{ __('How much is the premium plan?') }}</small></div><em>2</em></div>
                        <div class="lp-conv"><span class="lp-av alt">R</span><div><b>Rafael</b><small><i class="bi bi-instagram"></i> {{ __('Thanks, I will confirm today') }}</small></div></div>
                        <div class="lp-conv"><span class="lp-av alt2">W</span><div><b>{{ __('Website visitor') }}</b><small><i class="bi bi-window-stack"></i> {{ __('Do you deliver on weekends?') }}</small></div><em>1</em></div>
                        <div class="lp-conv"><span class="lp-av alt3">C</span><div><b>Clara</b><small><i class="bi bi-envelope-at"></i> {{ __('Invoice request') }}</small></div></div>
                    </div>
                    <div class="lp-thread">
                        <div class="lp-msg in">{{ __('Hi! How much is the premium plan?') }}</div>
                        <div class="lp-msg out"><span class="lp-ai"><i class="bi bi-robot"></i> {{ __('AI agent') }}</span>{{ __('Hi Marina! The premium plan is R$ 199/month and includes the AI reports. Want me to open a proposal?') }}</div>
                        <div class="lp-msg in">{{ __('Yes, please!') }}</div>
                        <div class="lp-deal"><i class="bi bi-kanban"></i> {{ __('Deal created in Proposal') }} <b>R$ 199</b></div>
                    </div>
                </div>
                <span class="lp-chip lp-chip-a"><i class="bi bi-trophy-fill"></i>{{ __('Deal won!') }}</span>
                <span class="lp-chip lp-chip-b"><i class="bi bi-lightbulb-fill"></i>{{ __('Weekly report ready') }}</span>
            </div>
            <p class="text-center small text-white-50 mt-3 mb-0">{{ __('Illustrative screen') }}</p>
        </div>
    </section>

    {{-- ───────── Channels + facts ───────── --}}
    <section class="lp-strip">
        <p class="lp-kicker">{{ __('All your channels in one inbox') }}</p>
        <div class="d-flex flex-wrap justify-content-center gap-3 gap-md-4">
            @foreach ($channels as [$icon, $name])
                <span class="lp-chan"><i class="bi {{ $icon }}"></i>{{ $name }}</span>
            @endforeach
        </div>
        <div class="row g-3 mt-3 text-center">
            @foreach ($facts as [$n, $t])
                <div class="col-6 col-md-3"><div class="lp-fact"><strong>{{ $n }}</strong><span>{{ $t }}</span></div></div>
            @endforeach
        </div>
    </section>

    {{-- ───────── Showcase 1: inbox ───────── --}}
    <section class="lp-section row align-items-center g-5">
        <div class="col-lg-6 order-lg-2">
            <span class="lp-eyebrow"><i class="bi bi-chat-dots me-1"></i>{{ __('Inbox') }}</span>
            <h2 class="lp-h2">{{ __('One inbox. Every customer. Zero lost messages.') }}</h2>
            <p class="lp-sub">{{ __('WhatsApp, Instagram, Telegram, Facebook, TikTok, e-mail and your website chat land in the same place, with the same tools: assignment, notes, labels, snooze and answer-time alerts.') }}</p>
            <ul class="lp-checks">
                <li>{{ __('Shared mailboxes: each one can send new conversations to a person and signs every e-mail') }}</li>
                <li>{{ __('Website chat: paste one line on your site and visitors become conversations') }}</li>
                <li>{{ __('Saved views: open "my urgent follow-ups" with one click') }}</li>
                <li>{{ __('The same person on WhatsApp and e-mail is one record') }}</li>
            </ul>
        </div>
        <div class="col-lg-6 order-lg-1">
            <div class="lp-panel">
                <div class="lp-panel-title"><i class="bi bi-window-stack"></i> {{ __('Website chat') }} <span class="badge text-bg-success ms-auto">{{ __('Active') }}</span></div>
                <div class="lp-code"><span>&lt;script src="https://your-domain/w/KEY.js" async&gt;&lt;/script&gt;</span></div>
                <div class="lp-views">
                    <span class="lp-pill on"><i class="bi bi-bookmark-fill"></i> {{ __('My urgent follow-ups') }}</span>
                    <span class="lp-pill"><i class="bi bi-bookmark"></i> {{ __('VIP customers') }}</span>
                    <span class="lp-pill"><i class="bi bi-bookmark"></i> {{ __('Unanswered today') }}</span>
                </div>
                <div class="lp-mailbox"><i class="bi bi-envelope-at"></i><div><b>sales@yourcompany.com</b><small>{{ __('New conversations go to') }} · Bia</small></div><span class="badge text-bg-secondary ms-auto">{{ __('Shared') }}</span></div>
            </div>
        </div>
    </section>

    {{-- ───────── Showcase 2: AI ───────── --}}
    <section class="lp-section row align-items-center g-5" id="ai">
        <div class="col-lg-6">
            <span class="lp-eyebrow"><i class="bi bi-robot me-1"></i>{{ __('AI') }}</span>
            <h2 class="lp-h2">{{ __('An AI that actually knows your business.') }}</h2>
            <p class="lp-sub">{{ __('Teach it with your FAQ, your website, your files and your products. It answers customers from that material, tells you what it could not answer, and writes you a report on how the business is doing.') }}</p>
            <div class="lp-learn">
                <span><i class="bi bi-patch-question"></i>FAQ</span><span><i class="bi bi-globe2"></i>{{ __('Your website') }}</span><span><i class="bi bi-file-earmark-text"></i>{{ __('Files') }}</span><span><i class="bi bi-box-seam"></i>{{ __('Products') }}</span><span><i class="bi bi-book"></i>{{ __('Knowledge base') }}</span>
            </div>
            <ul class="lp-checks">
                <li>{{ __('It never invents prices or policies: when it does not know, it says it will check and logs the question for you') }}</li>
                <li>{{ __('Reports in your language: key figures, findings and ranked actions, by e-mail or WhatsApp every week') }}</li>
                <li>{{ __('Numbers are calculated by the system from your data, and you can see exactly what the AI received') }}</li>
                <li>{{ __('Thumbs up/down on every answer, usage of the month and limits per plan') }}</li>
            </ul>
        </div>
        <div class="col-lg-6">
            <div class="lp-panel">
                <div class="lp-panel-title"><i class="bi bi-lightbulb"></i> {{ __('Business insights') }} <small class="ms-auto text-white-50">{{ __('Last 30 days') }}</small></div>
                <div class="row g-2 mb-3">
                    <div class="col-4"><div class="lp-kpi"><small>{{ __('New customers') }}</small><b>128</b><i class="up">+18%</i></div></div>
                    <div class="col-4"><div class="lp-kpi"><small>{{ __('Win rate') }}</small><b>34%</b><i class="up">+6 pp</i></div></div>
                    <div class="col-4"><div class="lp-kpi"><small>{{ __('Open pipeline') }}</small><b>R$ 86k</b><i>{{ __('42 deals') }}</i></div></div>
                </div>
                <div class="lp-bars">
                    <div><span>{{ __('Instagram') }}</span><u style="--w:86%"></u></div>
                    <div><span>WhatsApp</span><u style="--w:64%"></u></div>
                    <div><span>{{ __('Website chat') }}</span><u style="--w:38%"></u></div>
                </div>
                <div class="lp-rec high"><b>{{ __('Answer Instagram leads in under 10 minutes') }}</b><small>{{ __('Leads that wait tend to go cold: reply while they are still interested.') }}</small></div>
                <div class="lp-rec"><b>{{ __('Add the delivery policy to the FAQ') }}</b><small>{{ __('Asked 14 times this month and the AI could not answer.') }}</small></div>
            </div>
            <p class="text-center small text-white-50 mt-3 mb-0">{{ __('Illustrative screen') }}</p>
        </div>
    </section>

    {{-- ───────── Showcase 3: funnel ───────── --}}
    <section class="lp-section row align-items-center g-5">
        <div class="col-lg-6 order-lg-2">
            <span class="lp-eyebrow"><i class="bi bi-kanban me-1"></i>{{ __('Sales') }}</span>
            <h2 class="lp-h2">{{ __('A funnel that tells you what to do next.') }}</h2>
            <p class="lp-sub">{{ __('Every conversation can become a deal. Move it through the stages, follow the forecast and know why you win or lose.') }}</p>
            <ul class="lp-checks">
                <li>{{ __('Checklists per stage, alerts for stalled deals and followers on every deal') }}</li>
                <li>{{ __('Win/loss reasons, conversion per stage and sales per person and source') }}</li>
                <li>{{ __('Recurring revenue and renewals tracked before they expire') }}</li>
            </ul>
        </div>
        <div class="col-lg-6 order-lg-1">
            <div class="lp-kanban">
                <div><h6>{{ __('New') }} <span>3</span></h6><p>Marina <b>R$ 199</b></p><p>Studio Lume <b>R$ 540</b></p></div>
                <div><h6>{{ __('Proposal') }} <span>2</span></h6><p>Casa Verde <b>R$ 1.200</b></p></div>
                <div><h6>{{ __('Negotiation') }} <span>2</span></h6><p class="warn">Rafael <b>R$ 890</b><small>{{ __('Stalled 5 days') }}</small></p></div>
                <div class="won"><h6>{{ __('Won') }} <span>5</span></h6><p>Clínica Sol <b>R$ 2.400</b></p></div>
            </div>
        </div>
    </section>

    {{-- ───────── Everything, by theme ───────── --}}
    <section class="lp-section" id="features">
        <div class="text-center mb-4">
            <span class="lp-eyebrow"><i class="bi bi-grid me-1"></i>{{ __('Features') }}</span>
            <h2 class="lp-h2 mb-2">{{ __('Everything your operation needs, in one system') }}</h2>
            <p class="lp-sub mx-auto" style="max-width:640px">{{ __('Stop paying for five tools that do not talk to each other.') }}</p>
        </div>
        <ul class="nav nav-pills lp-tabs justify-content-center mb-4" role="tablist">
            @foreach ($groups as $i => [$icon, $title])
                <li class="nav-item" role="presentation"><button class="nav-link {{ $i === 0 ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#lpTab{{ $i }}" type="button" role="tab"><i class="bi {{ $icon }} me-1"></i>{{ $title }}</button></li>
            @endforeach
        </ul>
        <div class="tab-content">
            @foreach ($groups as $i => [$icon, $title, $sub, $items])
                <div class="tab-pane fade {{ $i === 0 ? 'show active' : '' }}" id="lpTab{{ $i }}" role="tabpanel">
                    <div class="lp-tabcard">
                        <div class="d-flex align-items-center gap-3 mb-2"><span class="lp-ico"><i class="bi {{ $icon }}"></i></span><h3 class="h4 fw-bold mb-0">{{ $title }}</h3></div>
                        <p class="text-white-50 mb-4">{{ $sub }}</p>
                        <div class="row g-3">
                            @foreach ($items as $it)<div class="col-md-6"><div class="lp-item"><i class="bi bi-check2-circle"></i><span>{{ $it }}</span></div></div>@endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="lp-replaces mt-4">
            <em class="text-white-50 small me-2 fst-normal">{{ __('Replaces') }}:</em>
            @foreach ($replaces as $r)<span>{{ $r }}</span>@endforeach
            <i class="bi bi-arrow-right"></i><b>{{ config('app.name', 'Sender') }}</b>
        </div>
    </section>

    {{-- ───────── Who it is for ───────── --}}
    <section class="lp-section" id="segments">
        <div class="text-center mb-4">
            <span class="lp-eyebrow"><i class="bi bi-bullseye me-1"></i>{{ __('Who it is for') }}</span>
            <h2 class="lp-h2 mb-2">{{ __('Built for businesses that sell by conversation') }}</h2>
            <p class="lp-sub mx-auto" style="max-width:640px">{{ __('The AI reports adapt to your type of business and look at what matters for it.') }}</p>
        </div>
        <div class="row g-3">
            @foreach ($segments as [$icon, $title, $text])
                <div class="col-lg-4 col-md-6"><div class="lp-seg"><span class="lp-ico"><i class="bi {{ $icon }}"></i></span><h3 class="h6 fw-bold mt-3 mb-1">{{ $title }}</h3><p class="mb-0">{{ $text }}</p></div></div>
            @endforeach
        </div>
    </section>

    {{-- ───────── How it works ───────── --}}
    <section class="lp-section">
        <div class="lp-steps">
            <h2 class="lp-h2 text-center mb-4">{{ __('Up and running in 4 steps') }}</h2>
            <div class="row g-4">
                @foreach ($steps as $i => [$t, $d])
                    <div class="col-md-6 col-lg-3 text-center">
                        <div class="lp-num">{{ $i + 1 }}</div>
                        <h3 class="h6 fw-bold text-white">{{ $t }}</h3>
                        <p class="text-white-50 small mb-0">{{ $d }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ───────── Plans ───────── --}}
    @if (isset($plans) && $plans->isNotEmpty())
    <section class="lp-section" id="plans">
        <div class="text-center mb-4">
            <span class="lp-eyebrow"><i class="bi bi-tag me-1"></i>{{ __('Plans') }}</span>
            <h2 class="lp-h2 mb-2">{{ __('Start free. Upgrade when you grow.') }}</h2>
        </div>
        <div class="row g-4 justify-content-center">
            @foreach ($plans as $plan)
                <div class="col-xl-3 col-md-6">
                    <div class="lp-plan {{ $plan->slug === 'pro' ? 'featured' : '' }}">
                        @if ($plan->slug === 'pro')<span class="lp-ribbon">{{ __('Most popular') }}</span>@endif
                        <h3 class="h5 fw-bold text-white mb-1">{{ $plan->name }}</h3>
                        <div class="lp-price"><strong>{{ $plan->isFree() ? __('Free') : 'R$ ' . number_format($plan->price_cents / 100, 0, ',', '.') }}</strong>@unless ($plan->isFree())<span>/ {{ __('month') }}</span>@endunless</div>
                        <ul class="list-unstyled small flex-grow-1 mb-3">
                            @foreach (\App\Models\Plan::limitLabels() as $key => $label)
                                <li><i class="bi bi-check2"></i><strong>{{ $plan->limit($key) === null ? __('Unlimited') : number_format($plan->limit($key), 0, ',', '.') }}</strong> {{ mb_strtolower($label) }}</li>
                            @endforeach
                            @foreach (\App\Models\Plan::featureLabels() as $key => $label)
                                <li class="{{ $plan->hasFeature($key) ? '' : 'off' }}"><i class="bi {{ $plan->hasFeature($key) ? 'bi-check2' : 'bi-x' }}"></i>{{ $label }}</li>
                            @endforeach
                        </ul>
                        <a href="{{ route('register.show') }}" class="btn {{ $plan->slug === 'pro' ? 'btn-warning' : 'btn-outline-light' }} fw-semibold">
                            {{ $plan->isFree() ? __('Start free') : ($plan->trial_days > 0 ? __('Try :n days free', ['n' => $plan->trial_days]) : __('Subscribe')) }}
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    {{-- ───────── FAQ ───────── --}}
    <section class="lp-section" id="faq">
        <h2 class="lp-h2 text-center mb-4">{{ __('Frequently asked questions') }}</h2>
        <div class="accordion accordion-flush lp-faq mx-auto" id="faqList" style="max-width:820px">
            @foreach ($faq as $i => [$q, $a])
                <div class="accordion-item">
                    <h3 class="accordion-header"><button class="accordion-button collapsed shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#faq{{ $i }}" aria-expanded="false">{{ $q }}</button></h3>
                    <div id="faq{{ $i }}" class="accordion-collapse collapse" data-bs-parent="#faqList"><div class="accordion-body">{{ $a }}</div></div>
                </div>
            @endforeach
        </div>
    </section>

    @guest
    <section class="lp-final">
        <h2 class="fw-bold mb-2">{{ __('Ready to stop losing customers?') }}</h2>
        <p class="mb-4">{{ __('Create your free account and have your first conversation organized today.') }}</p>
        <a href="{{ route('register.perform') }}" class="btn btn-dark btn-lg px-5 fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>{{ __('START FREE') }}</a>
    </section>
    @endguest

    <footer class="lp-footer">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div class="d-flex align-items-center"><img src="{{ asset('images/logo.jpg') }}" alt="" width="28" height="28" class="rounded-2 me-2"><b>{{ config('app.name', 'Sender') }}</b></div>
            <div class="d-flex flex-wrap gap-3 small">
                <a href="#features">{{ __('Features') }}</a><a href="#ai">{{ __('AI') }}</a>
                @if (isset($plans) && $plans->isNotEmpty())<a href="#plans">{{ __('Plans') }}</a>@endif
                <a href="#faq">FAQ</a>
                @guest<a href="{{ route('login.perform') }}">{{ __('Login') }}</a><a href="{{ route('register.perform') }}">{{ __('Sign-up') }}</a>@endguest
            </div>
            <small class="text-white-50">&copy; {{ date('Y') }} {{ config('app.name', 'Sender') }}</small>
        </div>
    </footer>
</div>

<a href="https://wa.me/5595981115965?text=Hi!+I+have+questions+about+Sender+and+would+like+to+speak+with+someone."
    target="_blank" rel="noopener" class="whatsapp-balloon shadow-lg" aria-label="{{ __('Talk to us') }}">
    <div class="balloon-content"><div class="online-indicator"><span class="blink-dot"></span></div><i class="bi bi-whatsapp"></i></div>
    <div class="balloon-text-wrapper"><span class="top-text">{{ __('Questions?') }}</span><span class="main-text">{{ __('Talk to us') }}</span></div>
</a>

<style>
    html { scroll-behavior: smooth; }
    body { background: #0a0a0b; color: #fff; overflow-x: hidden; }
    main.main-content { padding-top: .75rem; max-width: 1180px; }
    .lp { --amber: #ffc107; --line: rgba(255,255,255,.09); --card: rgba(255,255,255,.035); --muted: rgba(255,255,255,.66); }
    .lp { overflow-x: clip; }
    .lp section[id], .lp-section { scroll-margin-top: 90px; }
    .lp-section { padding: 4.5rem 0 0; }

    /* header */
    .lp-nav { margin: .25rem 0 0; padding: .55rem .9rem; border: 1px solid var(--line); border-radius: 1rem; background: rgba(14,14,16,.82); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); z-index: 1030; }
    .lp-nav .nav-link { color: var(--muted); font-weight: 500; font-size: .93rem; padding: .5rem .9rem; border-radius: .5rem; }
    .lp-nav .nav-link:hover { color: #fff; background: rgba(255,255,255,.06); }
    .lp-lang { min-width: 150px; }
    @media (max-width: 991.98px) { .lp-nav { border-radius: .9rem; } .lp-nav .navbar-collapse { padding: .75rem .25rem .25rem; } }

    /* type */
    .lp-eyebrow { display: inline-flex; align-items: center; font-size: .78rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--amber); background: rgba(255,193,7,.09); border: 1px solid rgba(255,193,7,.25); padding: .35rem .8rem; border-radius: 50px; margin-bottom: 1rem; }
    .lp-h1 { font-size: clamp(2.1rem, 4.4vw, 3.4rem); font-weight: 800; line-height: 1.08; letter-spacing: -.02em; margin-bottom: 1.1rem; }
    .lp-h2 { font-size: clamp(1.7rem, 3.4vw, 2.5rem); font-weight: 800; line-height: 1.15; letter-spacing: -.015em; margin-bottom: 1rem; }
    .lp-accent { color: var(--amber); }
    .lp-lead { font-size: clamp(1.05rem, 1.6vw, 1.25rem); color: var(--muted); max-width: 590px; margin-bottom: 1.75rem; }
    .lp-sub { color: var(--muted); font-size: 1.05rem; }
    .lp-cta { box-shadow: 0 10px 30px rgba(255,193,7,.25); }
    .lp-trust { gap: .4rem 1.5rem; color: var(--muted); font-size: .88rem; }
    .lp-trust i { color: var(--amber); margin-right: .35rem; }
    .lp-hero { padding: 3.5rem 0 1rem; position: relative; }
    .lp-hero::before { content: ""; position: absolute; inset: -10% -10% auto 30%; height: 420px; background: radial-gradient(closest-side, rgba(255,193,7,.13), transparent); pointer-events: none; z-index: -1; }
    .lp-checks { list-style: none; padding: 0; margin: 1.25rem 0 0; }
    .lp-checks li { position: relative; padding: .45rem 0 .45rem 1.9rem; color: rgba(255,255,255,.85); }
    .lp-checks li::before { content: "\F26A"; font-family: "bootstrap-icons"; position: absolute; left: 0; top: .42rem; color: var(--amber); }

    /* strip */
    .lp-strip { margin-top: 3rem; padding: 2rem 0 0; border-top: 1px solid var(--line); text-align: center; }
    .lp-kicker { color: var(--muted); font-size: .78rem; text-transform: uppercase; letter-spacing: .18em; margin-bottom: 1.1rem; }
    .lp-chan { display: inline-flex; align-items: center; gap: .5rem; font-weight: 600; color: #fff; }
    .lp-chan i { color: var(--amber); font-size: 1.25rem; }
    .lp-fact { border: 1px solid var(--line); background: var(--card); border-radius: 1rem; padding: 1.1rem .75rem; height: 100%; }
    .lp-fact strong { display: block; font-size: 1.9rem; font-weight: 800; color: var(--amber); line-height: 1.1; }
    .lp-fact span { color: var(--muted); font-size: .85rem; }

    /* hero window */
    .lp-window { position: relative; border: 1px solid var(--line); border-radius: 1.1rem; background: #121316; box-shadow: 0 40px 90px rgba(0,0,0,.55), 0 0 0 1px rgba(255,193,7,.06); overflow: visible; }
    .lp-window-bar { display: flex; align-items: center; gap: .4rem; padding: .7rem .9rem; border-bottom: 1px solid var(--line); }
    .lp-window-bar i { width: 10px; height: 10px; border-radius: 50%; background: #3a3b40; display: inline-block; }
    .lp-window-bar span { margin-left: .6rem; font-size: .78rem; color: var(--muted); }
    .lp-window-body { display: grid; grid-template-columns: 38% 62%; min-height: 330px; }
    .lp-list { border-right: 1px solid var(--line); padding: .5rem; display: flex; flex-direction: column; gap: .25rem; min-width: 0; }
    .lp-conv { display: flex; align-items: center; gap: .55rem; padding: .55rem; border-radius: .7rem; min-width: 0; }
    .lp-conv.active { background: rgba(255,193,7,.09); }
    .lp-conv div { min-width: 0; flex: 1; }
    .lp-conv b { display: block; font-size: .8rem; }
    .lp-conv small { display: block; font-size: .7rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lp-conv small i { color: var(--amber); }
    .lp-conv em { font-style: normal; background: var(--amber); color: #111; font-size: .65rem; font-weight: 700; border-radius: 50px; padding: .05rem .4rem; }
    .lp-av { width: 30px; height: 30px; flex: none; border-radius: 50%; background: #2c6bed; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: .78rem; }
    .lp-av.alt { background: #c13584; } .lp-av.alt2 { background: #0f9d6b; } .lp-av.alt3 { background: #7a5af8; }
    .lp-thread { padding: .9rem; display: flex; flex-direction: column; gap: .55rem; justify-content: flex-end; }
    .lp-msg { max-width: 88%; font-size: .8rem; padding: .55rem .75rem; border-radius: .9rem; line-height: 1.35; }
    .lp-msg.in { background: #23252b; align-self: flex-start; border-bottom-left-radius: .25rem; }
    .lp-msg.out { background: #0f5132; align-self: flex-end; border-bottom-right-radius: .25rem; }
    .lp-ai { display: inline-flex; gap: .3rem; align-items: center; font-size: .65rem; font-weight: 700; color: var(--amber); text-transform: uppercase; letter-spacing: .04em; margin-bottom: .2rem; }
    .lp-ai { display: flex; }
    .lp-deal { align-self: center; font-size: .72rem; color: var(--muted); border: 1px dashed rgba(255,193,7,.45); border-radius: 50px; padding: .3rem .8rem; }
    .lp-deal i { color: var(--amber); margin-right: .3rem; } .lp-deal b { color: #fff; margin-left: .3rem; }
    .lp-chip { position: absolute; background: rgba(20,20,22,.95); border: 1px solid rgba(255,193,7,.4); color: #fff; padding: .45rem .85rem; border-radius: 50px; font-size: .78rem; font-weight: 600; box-shadow: 0 14px 34px rgba(0,0,0,.55); animation: lpfloat 6s ease-in-out infinite; white-space: nowrap; }
    .lp-chip i { color: var(--amber); margin-right: .35rem; }
    .lp-chip-a { top: -16px; right: 6%; } .lp-chip-b { bottom: -16px; left: 6%; animation-delay: -3s; }
    @keyframes lpfloat { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-8px); } }
    @media (max-width: 575px) { .lp-window-body { grid-template-columns: 1fr; } .lp-list { display: none; } .lp-chip-a { right: 2%; } .lp-chip-b { left: 2%; } }

    /* panels */
    .lp-panel { border: 1px solid var(--line); background: linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)); border-radius: 1.1rem; padding: 1.25rem; box-shadow: 0 30px 70px rgba(0,0,0,.4); }
    .lp-panel-title { display: flex; align-items: center; gap: .5rem; font-weight: 700; margin-bottom: 1rem; }
    .lp-panel-title i { color: var(--amber); }
    .lp-code { background: #0b0c0e; border: 1px solid var(--line); border-radius: .7rem; padding: .8rem 1rem; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .75rem; color: #9be7a6; overflow-x: auto; white-space: nowrap; margin-bottom: 1rem; }
    .lp-views { display: flex; flex-wrap: wrap; gap: .5rem; margin-bottom: 1rem; }
    .lp-pill { font-size: .78rem; border: 1px solid var(--line); border-radius: 50px; padding: .35rem .8rem; color: var(--muted); }
    .lp-pill.on { color: #111; background: var(--amber); border-color: var(--amber); font-weight: 600; }
    .lp-mailbox { display: flex; align-items: center; gap: .8rem; border: 1px solid var(--line); border-radius: .8rem; padding: .7rem .9rem; background: rgba(255,255,255,.03); }
    .lp-mailbox > i { font-size: 1.4rem; color: var(--amber); }
    .lp-mailbox small { display: block; color: var(--muted); font-size: .75rem; }
    .lp-learn { display: flex; flex-wrap: wrap; gap: .5rem; margin: 1.1rem 0 .25rem; }
    .lp-learn span { border: 1px solid var(--line); border-radius: 50px; padding: .35rem .8rem; font-size: .82rem; background: var(--card); }
    .lp-learn i { color: var(--amber); margin-right: .35rem; }
    .lp-kpi { border: 1px solid var(--line); border-radius: .8rem; padding: .7rem; background: rgba(0,0,0,.25); height: 100%; }
    .lp-kpi small { display: block; color: var(--muted); font-size: .68rem; }
    .lp-kpi b { display: block; font-size: 1.35rem; line-height: 1.2; }
    .lp-kpi i { font-style: normal; font-size: .7rem; color: var(--muted); } .lp-kpi i.up { color: #4ade80; font-weight: 600; }
    .lp-bars { display: grid; gap: .5rem; margin-bottom: 1rem; }
    .lp-bars div { display: grid; grid-template-columns: 92px 1fr; align-items: center; gap: .6rem; font-size: .75rem; color: var(--muted); }
    .lp-bars u { display: block; height: 10px; width: var(--w); border-radius: 50px; background: linear-gradient(90deg, #ffc107, #ff9800); text-decoration: none; }
    .lp-rec { border-left: 3px solid #6c757d; background: rgba(255,255,255,.04); border-radius: .5rem; padding: .6rem .8rem; margin-top: .5rem; }
    .lp-rec.high { border-left-color: #ef4444; }
    .lp-rec b { display: block; font-size: .85rem; } .lp-rec small { color: var(--muted); font-size: .75rem; }

    /* kanban */
    .lp-kanban { display: grid; grid-template-columns: repeat(4, 1fr); gap: .6rem; border: 1px solid var(--line); background: #121316; border-radius: 1.1rem; padding: .9rem; box-shadow: 0 30px 70px rgba(0,0,0,.4); }
    .lp-kanban h6 { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); display: flex; justify-content: space-between; margin-bottom: .6rem; }
    .lp-kanban h6 span { background: rgba(255,255,255,.08); border-radius: 50px; padding: 0 .45rem; }
    .lp-kanban p { background: #1c1e23; border: 1px solid var(--line); border-radius: .6rem; padding: .55rem .6rem; font-size: .75rem; margin-bottom: .5rem; }
    .lp-kanban p b { display: block; color: var(--amber); font-size: .8rem; margin-top: .1rem; }
    .lp-kanban p.warn { border-color: rgba(239,68,68,.55); } .lp-kanban p.warn small { display: block; color: #fca5a5; font-size: .66rem; margin-top: .15rem; }
    .lp-kanban .won p { border-color: rgba(74,222,128,.5); } .lp-kanban .won h6 { color: #4ade80; }
    @media (max-width: 575px) { .lp-kanban { grid-template-columns: repeat(2, 1fr); } }

    /* tabs */
    .lp-tabs { gap: .4rem; }
    .lp-tabs .nav-link { color: var(--muted); border: 1px solid var(--line); border-radius: 50px; padding: .5rem 1rem; font-size: .9rem; background: var(--card); }
    .lp-tabs .nav-link:hover { color: #fff; }
    .lp-tabs .nav-link.active { background: var(--amber); border-color: var(--amber); color: #111; font-weight: 700; }
    .lp-tabcard { border: 1px solid var(--line); background: var(--card); border-radius: 1.25rem; padding: 1.75rem; }
    .lp-ico { width: 46px; height: 46px; border-radius: 12px; background: rgba(255,193,7,.12); color: var(--amber); display: inline-flex; align-items: center; justify-content: center; font-size: 1.4rem; flex: none; }
    .lp-item { display: flex; gap: .65rem; align-items: flex-start; height: 100%; padding: .75rem .9rem; border: 1px solid var(--line); border-radius: .8rem; background: rgba(0,0,0,.18); }
    .lp-item i { color: var(--amber); margin-top: .1rem; }
    .lp-replaces { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: .5rem; }
    .lp-replaces span { border: 1px solid var(--line); border-radius: 50px; padding: .25rem .8rem; font-size: .8rem; color: var(--muted); text-decoration: line-through; text-decoration-color: rgba(255,193,7,.6); }
    .lp-replaces > i { color: var(--amber); } .lp-replaces b { color: #111; background: var(--amber); border-radius: 50px; padding: .25rem .9rem; font-size: .85rem; }

    /* segments */
    .lp-seg { height: 100%; border: 1px solid var(--line); background: var(--card); border-radius: 1rem; padding: 1.25rem; transition: transform .25s, border-color .25s; }
    .lp-seg:hover { transform: translateY(-4px); border-color: rgba(255,193,7,.5); }
    .lp-seg p { color: var(--muted); font-size: .9rem; }

    /* steps */
    .lp-steps { background: linear-gradient(135deg, rgba(255,193,7,.10), rgba(255,255,255,.02)); border: 1px solid rgba(255,193,7,.22); border-radius: 1.5rem; padding: 2.5rem 1.5rem; }
    .lp-num { width: 52px; height: 52px; border-radius: 50%; background: var(--amber); color: #111; font-weight: 800; font-size: 1.4rem; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }

    /* plans */
    .lp-plan { position: relative; display: flex; flex-direction: column; height: 100%; border: 1px solid var(--line); background: var(--card); border-radius: 1.25rem; padding: 1.5rem; transition: transform .25s, border-color .25s; }
    .lp-plan:hover { transform: translateY(-5px); }
    .lp-plan.featured { border-color: var(--amber); background: linear-gradient(180deg, rgba(255,193,7,.09), rgba(255,255,255,.02)); box-shadow: 0 20px 60px rgba(255,193,7,.08); }
    .lp-ribbon { position: absolute; top: -12px; left: 50%; transform: translateX(-50%); background: var(--amber); color: #111; font-size: .7rem; font-weight: 800; text-transform: uppercase; letter-spacing: .05em; padding: .2rem .8rem; border-radius: 50px; white-space: nowrap; }
    .lp-price { margin: .4rem 0 1rem; } .lp-price strong { font-size: 2.1rem; font-weight: 800; } .lp-price span { color: var(--muted); font-size: .85rem; margin-left: .3rem; }
    .lp-plan li { padding: .22rem 0; color: rgba(255,255,255,.88); } .lp-plan li i { color: var(--amber); margin-right: .4rem; } .lp-plan li.off { color: rgba(255,255,255,.35); } .lp-plan li.off i { color: #6c757d; }

    /* faq */
    .lp-faq .accordion-item { background: transparent; border-color: var(--line); }
    .lp-faq .accordion-button { background: transparent; color: #fff; font-weight: 600; }
    .lp-faq .accordion-button:not(.collapsed) { color: var(--amber); background: transparent; }
    .lp-faq .accordion-button::after { filter: invert(1); }
    .lp-faq .accordion-body { color: var(--muted); }

    /* final + footer */
    .lp-final { margin-top: 4.5rem; text-align: center; color: #111; background: linear-gradient(135deg, #ffc107, #ff9800); border-radius: 1.5rem; padding: 3.25rem 1.5rem; }
    .lp-footer { margin-top: 3.5rem; padding: 1.75rem 0 5rem; border-top: 1px solid var(--line); }
    .lp-footer a { color: var(--muted); text-decoration: none; } .lp-footer a:hover { color: #fff; }

    /* whatsapp balloon */
    .whatsapp-balloon { position: fixed; bottom: 24px; right: 24px; background: #25D366; color: #fff !important; text-decoration: none; display: flex; align-items: center; padding: 10px 20px; border-radius: 50px; z-index: 1040; box-shadow: 0 10px 25px rgba(37,211,102,.4); border: 2px solid rgba(255,255,255,.1); }
    .balloon-content { font-size: 28px; margin-right: 12px; display: flex; align-items: center; position: relative; }
    .balloon-text-wrapper { display: flex; flex-direction: column; line-height: 1.2; }
    .top-text { font-size: .7rem; text-transform: uppercase; letter-spacing: 1px; opacity: .9; }
    .main-text { font-size: 1rem; font-weight: 700; white-space: nowrap; }
    .online-indicator { position: absolute; top: -2px; right: -5px; width: 12px; height: 12px; background: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
    .blink-dot { width: 8px; height: 8px; background: #00e676; border-radius: 50%; animation: lppulse 2s infinite; }
    @keyframes lppulse { 0% { box-shadow: 0 0 0 0 rgba(0,230,118,.7); } 70% { box-shadow: 0 0 0 10px rgba(0,230,118,0); } 100% { box-shadow: 0 0 0 0 rgba(0,230,118,0); } }
    @media (max-width: 575px) { .balloon-text-wrapper { display: none; } .whatsapp-balloon { padding: 10px 14px; } .balloon-content { margin-right: 0; } .lp-section { padding-top: 3.25rem; } }
    @media (prefers-reduced-motion: reduce) { html { scroll-behavior: auto; } .lp-chip, .blink-dot { animation: none; } .lp-seg, .lp-plan { transition: none; } }
</style>
@endsection
