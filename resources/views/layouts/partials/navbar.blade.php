@auth
<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
  <div class="container">
    <a class="navbar-brand d-flex align-items-center" href="/">
      {{-- LOGO DA EMPRESA --}}
      <img src="{{ asset('images/logo.jpg') }}" alt="Logo" width="55" height="55" class="me-2 d-inline-block align-text-top">
      <span class="fw-bold">{{ config('app.name', 'Sender') }}</span>
    </a>

    <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navMain">
      @auth
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a href="/" class="nav-link {{ request()->is('/') ? 'active text-secondary' : '' }}">
            <i class="bi bi-speedometer2 me-1"></i> Dashboard
          </a>
        </li>
        <li class="nav-item">
          <a href="/instances" class="nav-link text-white"><i class="bi bi-whatsapp me-1"></i> Whatsapp</a>
        </li>
        <li class="nav-item">
          <a href="/campaigns" class="nav-link text-white"><i class="bi bi-megaphone me-1"></i> Campaign</a>
        </li>
        <li class="nav-item">
          <a href="/contacts" class="nav-link text-white"><i class="bi bi-people me-1"></i> Contacts</a>
        </li>
        <li class="nav-item">
          <a href="{{route('campaign-items.index')}}" class="nav-link text-white"><i class="bi bi-list-check me-1"></i> Itens</a>
        </li>

        @if(Auth::user()->role === 'admin')
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle text-info fw-bold" href="#" id="adminDrop" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-shield-lock-fill me-1"></i> Admin
          </a>
          <ul class="dropdown-menu dropdown-menu-dark shadow-lg border-0" aria-labelledby="adminDrop">
            <li>
              <a class="dropdown-item {{ request()->is('admin/users*') ? 'active' : '' }}" href="{{ route('users.index') }}">
                <i class="bi bi-person-gear me-2"></i>Gestão de Usuários
              </a>
            </li>
            <li><a class="dropdown-item" href="#"><i class="bi bi-hdd-network me-2"></i>Instâncias Globais</a></li>
            <li>
              <hr class="dropdown-divider bg-secondary">
            </li>
            <li><a class="dropdown-item text-warning" href="#"><i class="bi bi-graph-up-arrow me-2"></i>Logs</a></li>
          </ul>
        </li>
        @endif
      </ul>

      <div class="d-flex align-items-center border-top border-secondary pt-3 pt-lg-0 border-lg-0">

        {{-- INJEÇÃO DO PARTIAL DE NOTIFICAÇÕES --}}
        <div class="me-3">
          @include('layouts.partials.notifications')
        </div>

        <span class="text-white-50 me-3 d-none d-lg-inline small">
          {{ auth()->user()->name }}
        </span>
        <a href="{{ route('logout.perform') }}" class="btn btn-sm btn-outline-danger w-100 w-lg-auto">
          <i class="bi bi-box-arrow-right"></i> Sair
        </a>
      </div>
      @endauth

      @guest
      <div class="ms-auto pt-3 pt-lg-0">
        <a href="{{ route('login.perform') }}" class="btn btn-sm btn-outline-light me-2">Login</a>
        <a href="{{ route('register.perform') }}" class="btn btn-sm btn-warning">Sign-up</a>
      </div>
      @endguest
    </div>
  </div>
</nav>
@endauth