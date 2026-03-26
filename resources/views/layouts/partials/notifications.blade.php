<li class="nav-item dropdown ms-lg-3">
    <a class="nav-link dropdown-toggle position-relative" href="#" id="notifDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-bell fs-5"></i>
        @php $unreadCount = auth()->user()->unreadNotifications->count(); @endphp
        @if($unreadCount > 0)
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
                {{ $unreadCount }}
                <span class="visually-hidden">mensagens não lidas</span>
            </span>
        @endif
    </a>
    <ul class="dropdown-menu dropdown-menu-end shadow border-0" aria-labelledby="notifDropdown" style="width: 300px; max-height: 400px; overflow-y: auto;">
        <li class="dropdown-header border-bottom pb-2 mb-2">Notificações Recentes</li>
        
        @forelse(auth()->user()->unreadNotifications as $notification)
            <li>
                <div class="dropdown-item py-3 border-bottom @if($loop->first) bg-light @endif">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0 me-3">
                            <i class="bi bi-check2-circle text-success fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <p class="mb-0 small fw-bold text-dark">{{ $notification->data['title'] ?? 'Sync Concluído' }}</p>
                            <p class="mb-0 small text-muted">{{ $notification->data['message'] }}</p>
                            <small class="text-primary font-monospace">{{ $notification->created_at->diffForHumans() }}</small>
                        </div>
                    </div>
                </div>
            </li>
        @empty
            <li class="p-4 text-center">
                <i class="bi bi-bell-slash text-muted d-block mb-2 fs-3"></i>
                <span class="small text-muted">Tudo limpo por aqui!</span>
            </li>
        @endforelse

        @if($unreadCount > 0)
            <li>
                <form action="{{ route('notifications.clear') }}" method="POST">
                    @csrf
                    <button type="submit" class="dropdown-item text-center small text-primary fw-bold py-2">
                        Marcar todas como lidas
                    </button>
                </form>
            </li>
        @endif
    </ul>
</li>