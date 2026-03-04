<header class="p-3 bg-dark text-white shadow-sm">
  <div class="container">
    <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-start">
      
      <a href="/" class="d-flex align-items-center mb-2 mb-lg-0 text-white text-decoration-none">
        <i class="bi bi-radar me-2" style="font-size: 1.5rem; color: #ffc107;"></i>
        <span class="fs-4 fw-bold me-4">{{ env('APP_NAME') }}</span>
      </a>

      @auth
      <ul class="nav col-12 col-lg-auto me-lg-auto mb-2 justify-content-center mb-md-0">
        <li><a href="/" class="nav-link px-2 text-secondary"><i class="bi bi-speedometer2 me-1"></i> Dashboard</a></li>
        <li><a href="/whatsapp" class="nav-link px-2 text-white"><i class="bi bi-whatsapp me-1"></i> Whatsapp</a></li>
        <li><a href="/campaigns" class="nav-link px-2 text-white"><i class="bi bi-megaphone me-1"></i> Campaign</a></li>
        <li><a href="/contacts" class="nav-link px-2 text-white"><i class="bi bi-people me-1"></i> Contacts</a></li>
        <li><a href="{{route('campaign-items.index')}}" class="nav-link px-2 text-white"><i class="bi bi-list-check me-1"></i> Itens</a></li>
      </ul>
      @endauth

      <div class="d-flex align-items-center ms-auto">
        <form class="me-3">
          <div class="input-group">
            <span class="input-group-text bg-secondary border-secondary text-white"><i class="bi bi-search"></i></span>
            <input type="search" class="form-control form-control-dark bg-secondary border-secondary text-white" placeholder="Buscar..." aria-label="Search">
          </div>
        </form>

        @auth
          <div class="d-flex align-items-center border-start ps-3 ms-2">
            <span class="me-3 d-none d-lg-inline small">
                <i class="bi bi-person-circle me-1"></i> {{ auth()->user()->name }}
            </span>
            <div class="text-end">
              <a href="{{ route('logout.perform') }}" class="btn btn-sm btn-outline-danger">
                <i class="bi bi-box-arrow-right"></i> Sair
              </a>
            </div>
          </div>
        @endauth

        @guest
          <div class="text-end">
            <a href="{{ route('login.perform') }}" class="btn btn-outline-light me-2">Login</a>
            <a href="{{ route('register.perform') }}" class="btn btn-warning">Sign-up</a>
          </div>
        @endguest
      </div>

    </div>
  </div>
</header>