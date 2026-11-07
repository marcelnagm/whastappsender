@auth
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="/">
      {{-- Company logo --}}
      <img src="{{ asset('images/logo.jpg') }}" alt="{{ __('Logo') }}" width="55" height="55" class="me-2 d-inline-block align-text-top">
      <span class="fw-bold">{{ config('app.name', 'Sender') }}</span>
    </a>

    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="{{ __('Toggle navigation') }}">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      @auth
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a href="/" class="nav-link {{ request()->is('/') ? 'active text-secondary' : '' }}">
            <i class="bi bi-speedometer2 me-1"></i> {{ __('Dashboard') }}
          </a>
        </li>
        @teamcan('channels')
        <li class="nav-item">
          <a href="/instances" class="nav-link text-white"><i class="bi bi-whatsapp me-1"></i> {{ __('Whatsapp') }}</a>
        </li>
        @endteamcan
        @teamcan('channels')
        <li class="nav-item">
          <a href="{{ route('telegram.index') }}" class="nav-link {{ request()->is('telegram*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-telegram me-1"></i> {{ __('Telegram') }}</a>
        </li>
        @endteamcan
        @teamcan('channels')
        <li class="nav-item">
          <a href="{{ route('tiktok.index') }}" class="nav-link {{ request()->is('tiktok*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-tiktok me-1"></i> {{ __('TikTok') }}</a>
        </li>
        @endteamcan
        @teamcan('channels')
        <li class="nav-item">
          <a href="{{ route('instagram.index') }}" class="nav-link {{ request()->is('instagram*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-instagram me-1"></i> {{ __('Instagram') }}</a>
        </li>
        @endteamcan
        @teamcan('channels')
        <li class="nav-item">
          <a href="{{ route('facebook.index') }}" class="nav-link {{ request()->is('facebook*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-facebook me-1"></i> {{ __('Facebook') }}</a>
        </li>
        @endteamcan
        @teamcan('channels')
        <li class="nav-item">
          <a href="{{ route('email.index') }}" class="nav-link {{ request()->is('email*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-envelope me-1"></i> {{ __('Email') }}</a>
        </li>
        @endteamcan
        @teamcan('chat')
        <li class="nav-item">
          <a href="{{ route('chat.index') }}" class="nav-link {{ request()->is('chat*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-chat-dots me-1"></i> {{ __('Chat') }}</a>
        </li>
        @endteamcan
        @teamcan('team')
        <li class="nav-item">
          <a href="{{ route('hours.edit') }}" class="nav-link {{ request()->is('hours*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-clock me-1"></i> {{ __('Opening hours') }}</a>
        </li>
        @endteamcan
        @teamcan('reports')
        <li class="nav-item">
          <a href="{{ route('stats.index') }}" class="nav-link {{ request()->is('stats*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-bar-chart-line me-1"></i> {{ __('Statistics') }}</a>
        </li>
        @endteamcan
        @teamcan('reports')
        <li class="nav-item">
          <a href="{{ route('team-report.index') }}" class="nav-link {{ request()->is('team-report*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-trophy me-1"></i> {{ __('Team performance') }}</a>
        </li>
        @endteamcan
        @teamcan('ai')
        <li class="nav-item">
          <a href="{{ route('ai.index') }}" class="nav-link {{ request()->is('ai*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-robot me-1"></i> {{ __('AI') }}</a>
        </li>
        @endteamcan
        @teamcan('campaigns.view')
        <li class="nav-item">
          <a href="/campaigns" class="nav-link text-white"><i class="bi bi-megaphone me-1"></i> {{ __('Campaign') }}</a>
        </li>
        @endteamcan
        @teamcan('contacts.view')
        <li class="nav-item">
          <a href="/contacts" class="nav-link text-white"><i class="bi bi-people me-1"></i> {{ __('Contacts') }}</a>
        </li>
        @endteamcan
        @teamcan('campaigns.view')
        <li class="nav-item">
          <a href="{{route('campaign-items.index')}}" class="nav-link text-white"><i class="bi bi-list-check me-1"></i> {{ __('Items') }}</a>
        </li>
        @endteamcan
        @teamcan('campaigns.approve')
        <li class="nav-item">
          <a href="{{ route('approvals.index') }}" class="nav-link {{ request()->is('approvals*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-check2-square me-1"></i> {{ __('Approvals') }}</a>
        </li>
        @endteamcan
        @teamcan('deals')
        <li class="nav-item">
          <a href="{{ route('pipeline.index') }}" class="nav-link {{ request()->is('pipeline*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-kanban me-1"></i> {{ __('Pipeline') }}</a>
        </li>
        @endteamcan
        @teamcan('chat')
        <li class="nav-item">
          <a href="{{ route('replies.index') }}" class="nav-link {{ request()->is('replies*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-lightning-charge me-1"></i> {{ __('Quick replies') }}</a>
        </li>
        @endteamcan
        @teamcan('contacts.manage')
        <li class="nav-item">
          <a href="{{ route('audience.index') }}" class="nav-link {{ request()->is('audience*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-funnel me-1"></i> {{ __('Segments') }}</a>
        </li>
        @endteamcan
        @teamcan('contacts.manage')
        <li class="nav-item">
          <a href="{{ route('consent.index') }}" class="nav-link {{ request()->is('consent*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-shield-check me-1"></i> {{ __('Consent') }}</a>
        </li>
        @endteamcan
        @teamcan('automations')
        <li class="nav-item">
          <a href="{{ route('automations.index') }}" class="nav-link {{ request()->is('automations*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-diagram-3 me-1"></i> {{ __('Automations') }}</a>
        </li>
        @endteamcan
        @teamcan('tasks')
        <li class="nav-item">
          <a href="{{ route('tasks.index') }}" class="nav-link {{ request()->is('tasks*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-alarm me-1"></i> {{ __('Follow-ups') }}</a>
        </li>
        @endteamcan
        @teamcan('forms')
        <li class="nav-item">
          <a href="{{ route('forms.index') }}" class="nav-link {{ request()->is('forms*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-ui-checks me-1"></i> {{ __('Forms') }}</a>
        </li>
        @endteamcan
        @teamcan('team')
        <li class="nav-item">
          <a href="{{ route('team.index') }}" class="nav-link {{ request()->is('team*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-people-fill me-1"></i> {{ __('Team') }}</a>
        </li>
        @endteamcan
        @teamcan('owner')
        <li class="nav-item">
          <a href="{{ route('billing.index') }}" class="nav-link {{ request()->is('billing*') ? 'active text-secondary' : 'text-white' }}"><i class="bi bi-credit-card me-1"></i> {{ __('Plan') }}</a>
        </li>
        @endteamcan
        @teamcan('owner')
        <li class="nav-item">
          <a href="{{ route('profile.edit') }}" class="nav-link {{ request()->is('me/profile') ? 'active text-secondary' : 'text-white' }}">
            <i class="bi bi-person-lines-fill me-1"></i> {{ __('My Profile') }}
          </a>
        </li>
        @endteamcan

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

      <div class="d-flex align-items-center border-top border-secondary pt-3 pt-lg-0 border-lg-0">

        {{-- Language selector --}}
        <div class="me-3">
          @include('layouts.partials.language-selector')
        </div>

        {{-- Notifications partial --}}
        <div class="me-3">
          @include('layouts.partials.notifications')
        </div>

        <span class="text-white-50 me-3 d-none d-lg-inline small">
          {{ auth()->user()->name }}
        </span>
        <a href="{{ route('logout.perform') }}" class="btn btn-sm btn-outline-danger w-100 w-lg-auto">
          <i class="bi bi-box-arrow-right"></i> {{ __('Log out') }}
        </a>
      </div>
      @endauth

      @guest
      <div class="ms-auto pt-3 pt-lg-0">
        <a href="{{ route('login.perform') }}" class="btn btn-sm btn-outline-light me-2">{{ __('Login') }}</a>
        <a href="{{ route('register.perform') }}" class="btn btn-sm btn-warning">{{ __('Sign-up') }}</a>
      </div>
      @endguest
    </div>
  </div>
</nav>
@endauth
