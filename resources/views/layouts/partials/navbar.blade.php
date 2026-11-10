@auth
@php
    $ctx = app(\App\Services\Team\TeamContext::class);
    // Menu grouped by theme: [icon, label, items]; an item is [permission|null, url, icon, label, active-pattern]. Groups with nothing the person may open are not drawn.
    $menu = [
        ['bi-chat-dots', __('Chat'), [['chat', route('chat.index'), 'bi-chat-dots', __('Chat'), 'chat*']], 'solo'],
        ['bi-broadcast', __('Channels'), [
            ['channels', url('/instances'), 'bi-whatsapp', __('Whatsapp'), 'instances*'],
            ['channels', route('telegram.index'), 'bi-telegram', __('Telegram'), 'telegram*'],
            ['channels', route('instagram.index'), 'bi-instagram', __('Instagram'), 'instagram*'],
            ['channels', route('facebook.index'), 'bi-facebook', __('Facebook'), 'facebook*'],
            ['channels', route('tiktok.index'), 'bi-tiktok', __('TikTok'), 'tiktok*'],
            ['channels', route('email.index'), 'bi-envelope', __('Email'), 'email*'],
            ['channels', route('webchat.index'), 'bi-window-stack', __('Website chat'), 'webchat*'],
        ]],
        ['bi-kanban', __('Sales'), [
            ['deals', route('pipeline.index'), 'bi-kanban', __('Pipeline'), 'pipeline*'],
            ['tasks', route('tasks.index'), 'bi-alarm', __('Follow-ups'), 'tasks*'],
            ['automations', route('bookings.index'), 'bi-calendar-check', __('Bookings'), 'bookings*'],
        ]],
        ['bi-people', __('Customers'), [
            ['contacts.view', url('/contacts'), 'bi-people', __('Contacts'), 'contacts*'],
            ['contacts.manage', route('companies.index'), 'bi-building', __('Companies'), 'companies*'],
            ['contacts.manage', route('audience.index'), 'bi-funnel', __('Segments'), 'audience*'],
            ['forms', route('forms.index'), 'bi-ui-checks', __('Forms'), 'forms*'],
            ['contacts.manage', route('consent.index'), 'bi-shield-check', __('Consent'), 'consent*'],
        ]],
        ['bi-megaphone', __('Marketing'), [
            ['campaigns.view', url('/campaigns'), 'bi-megaphone', __('Campaign'), 'campaigns*'],
            ['campaigns.view', route('campaign-items.index'), 'bi-list-check', __('Items'), 'campaign-items*'],
            ['campaigns.approve', route('approvals.index'), 'bi-check2-square', __('Approvals'), 'approvals*'],
            ['automations', route('sequences.index'), 'bi-arrow-repeat', __('Follow-up sequences'), 'sequences*'],
        ]],
        ['bi-robot', __('AI & automation'), [
            ['ai', route('ai.index'), 'bi-robot', __('AI agents'), 'ai,ai/agents*,ai/toggle'],
            ['ai', route('ai.knowledge.index'), 'bi-mortarboard', __('Business knowledge'), 'ai/knowledge*'],
            ['ai', route('ai.insights.index'), 'bi-lightbulb', __('Business insights'), 'ai/insights*'],
            ['automations', route('automations.index'), 'bi-diagram-3', __('Automations'), 'automations*'],
            ['automations', route('typebot.index'), 'bi-signpost-split', 'Typebot', 'typebot*'],
            ['automations', route('kb.index'), 'bi-book', __('Knowledge base'), 'kb*'],
            ['chat', route('replies.index'), 'bi-lightning-charge', __('Quick replies'), 'replies*'],
        ]],
        ['bi-graph-up', __('Reports'), [
            ['reports', route('stats.index'), 'bi-bar-chart-line', __('Statistics'), 'stats*'],
            ['reports', route('team-report.index'), 'bi-trophy', __('Team performance'), 'team-report*'],
        ]],
        ['bi-sliders', __('Settings'), [
            ['team', route('team.index'), 'bi-people-fill', __('Team'), 'team*'],
            ['team', route('hours.edit'), 'bi-clock', __('Opening hours'), 'hours*'],
            ['team', route('sla.index'), 'bi-stopwatch', __('Response SLA'), 'sla*'],
            ['team', route('csat.index'), 'bi-emoji-smile', __('Satisfaction survey'), 'csat*'],
            ['team', route('webhooks-out.index'), 'bi-plug', __('Outbound webhooks'), 'webhooks-out*'],
            ['automations', route('audit.index'), 'bi-shield-lock', __('Audit log'), 'audit*'],
            ['automations', route('exports.index'), 'bi-download', __('Data export'), 'exports*'],
            ['owner', route('billing.index'), 'bi-credit-card', __('Plan'), 'billing*'],
            ['owner', route('profile.edit'), 'bi-person-lines-fill', __('My Profile'), 'me/profile'],
        ]],
    ];
    $menu = collect($menu)->map(function ($g) use ($ctx) {
        $g['visible'] = collect($g[2])->filter(fn ($i) => $ctx->can($i[0]))->values();

        return $g;
    })->filter(fn ($g) => $g['visible']->isNotEmpty())->values();
    $on = fn ($pattern) => request()->is(explode(',', $pattern));
    $initial = mb_strtoupper(mb_substr(trim(auth()->user()->name) ?: '?', 0, 1));
@endphp
<style>
  .app-nav { background: #111418; border-bottom: 1px solid rgba(255,255,255,.08); }
  .app-nav .nav-link { font-weight: 500; font-size: .92rem; padding: .5rem .6rem; border-radius: .5rem; color: rgba(255,255,255,.78); white-space: nowrap; }
  .app-nav .nav-link:hover, .app-nav .nav-link:focus { color: #fff; background: rgba(255,255,255,.06); }
  .app-nav .nav-link.active, .app-nav .show > .nav-link { color: #ffc107; background: rgba(255,193,7,.10); }
  .app-nav .dropdown-menu { border: 1px solid rgba(255,255,255,.1); border-radius: .75rem; padding: .4rem; min-width: 230px; box-shadow: 0 18px 40px rgba(0,0,0,.45); }
  .app-nav .dropdown-item { border-radius: .5rem; padding: .5rem .75rem; font-size: .9rem; display: flex; align-items: center; gap: .6rem; }
  .app-nav .dropdown-item i { width: 1.1rem; text-align: center; opacity: .85; }
  .app-nav .dropdown-item.active, .app-nav .dropdown-item:active { background: rgba(255,193,7,.16); color: #ffc107; }
  .app-nav .user-toggle::after { display: none; }
  .app-nav .avatar { width: 32px; height: 32px; border-radius: 50%; background: #ffc107; color: #111; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; font-size: .9rem; }
  @media (max-width: 991.98px) {
    .app-nav .navbar-collapse { max-height: calc(100vh - 70px); overflow-y: auto; padding-bottom: .75rem; }
    .app-nav .dropdown-menu { box-shadow: none; background: rgba(255,255,255,.04); margin: .1rem 0 .4rem .6rem; }
    .app-nav .nav-link { padding: .7rem .8rem; }
  }
</style>
<nav class="navbar navbar-expand-lg navbar-dark app-nav sticky-top">
  <div class="container-fluid px-lg-4">
    <a class="navbar-brand d-flex align-items-center me-3" href="/">
      <img src="{{ asset('images/logo.jpg') }}" alt="{{ __('Logo') }}" width="36" height="36" class="me-2 rounded-2">
      <span class="fw-bold">{{ config('app.name', 'Sender') }}</span>
    </a>

    <button class="navbar-toggler border-0 shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-lg-1">
        <li class="nav-item">
          <a href="/" class="nav-link {{ request()->is('/') ? 'active' : '' }}"><i class="bi bi-speedometer2 me-1"></i> {{ __('Dashboard') }}</a>
        </li>

        @foreach ($menu as $g)
          @php [$icon, $label] = $g; $items = $g['visible']; @endphp
          @if (($g[3] ?? '') === 'solo' || $items->count() === 1)
            @php $i = $items->first(); @endphp
            <li class="nav-item"><a href="{{ $i[1] }}" class="nav-link {{ $on($i[4]) ? 'active' : '' }}"><i class="bi {{ $i[2] }} me-1"></i> {{ $i[3] }}</a></li>
          @else
            @php $open = $items->contains(fn ($i) => $on($i[4])); @endphp
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle {{ $open ? 'active' : '' }}" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi {{ $icon }} me-1"></i> {{ $label }}</a>
              <ul class="dropdown-menu dropdown-menu-dark">
                @foreach ($items as $i)
                  <li><a class="dropdown-item {{ $on($i[4]) ? 'active' : '' }}" href="{{ $i[1] }}"><i class="bi {{ $i[2] }}"></i>{{ $i[3] }}</a></li>
                @endforeach
              </ul>
            </li>
          @endif
        @endforeach

        @if(Auth::user()->role === 'admin')
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle text-info fw-bold" href="#" id="adminDrop" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-shield-lock-fill me-1"></i> {{ __('Admin') }}
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-0" aria-labelledby="adminDrop">
            <li>
              <a class="dropdown-item {{ request()->is('admin/users*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                <i class="bi bi-person-gear me-2"></i>{{ __('User Management') }}
              </a>
            </li>
            <li>
              <a class="dropdown-item {{ request()->is('admin/billing*') ? 'active' : '' }}" href="{{ route('admin.billing.index') }}">
                <i class="bi bi-cash-coin me-2"></i>{{ __('Billing') }}
              </a>
            </li>
            <li>
              <a class="dropdown-item {{ request()->is('admin/signup-welcome*') ? 'active' : '' }}" href="{{ route('admin.welcome.edit') }}">
                <i class="bi bi-envelope-heart me-2"></i>{{ __('Signup welcome') }}
              </a>
            </li>
            <li>
              <a class="dropdown-item {{ request()->is('admin/payment-gateway*') ? 'active' : '' }}" href="{{ route('admin.gateway.edit') }}">
                <i class="bi bi-credit-card-2-front me-2"></i>{{ __('Payment gateway') }}
              </a>
            </li>
            <li><a class="dropdown-item" href="#"><i class="bi bi-hdd-network me-2"></i>{{ __('Global Instances') }}</a></li>
            <li>
              <hr class="dropdown-divider bg-secondary">
            </li>
            <li><a class="dropdown-item text-warning" href="#"><i class="bi bi-graph-up-arrow me-2"></i>{{ __('Logs') }}</a></li>
            <li><a href="{{ route('panic.index') }}" class="dropdown-item text-warning" href="#"><i class="bi bi-activity me-2"></i>{{ __('PANIC!') }}</a></li>
          </ul>
        </li>
        @endif
      </ul>

      <div class="d-flex align-items-center gap-3 border-top border-secondary border-opacity-25 pt-3 pt-lg-0 mt-2 mt-lg-0">
        <div style="max-width:140px">@include('layouts.partials.language-selector')</div>
        <div>@include('layouts.partials.notifications')</div>
        <div class="dropdown ms-auto ms-lg-0">
          <a class="d-flex align-items-center text-decoration-none user-toggle text-white" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <span class="avatar me-2">{{ $initial }}</span><span class="d-none d-xxl-inline small">{{ auth()->user()->name }}</span>
          </a>
          <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" style="min-width:210px">
            <li class="px-3 py-2 small text-white-50">{{ auth()->user()->name }}</li>
            @teamcan('owner')
            <li><a class="dropdown-item" href="{{ route('profile.edit') }}"><i class="bi bi-person-lines-fill"></i>{{ __('My Profile') }}</a></li>
            <li><a class="dropdown-item" href="{{ route('billing.index') }}"><i class="bi bi-credit-card"></i>{{ __('Plan') }}</a></li>
            @endteamcan
            <li><hr class="dropdown-divider border-secondary"></li>
            <li><a class="dropdown-item text-danger" href="{{ route('logout.perform') }}"><i class="bi bi-box-arrow-right"></i>{{ __('Log out') }}</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</nav>
@endauth
