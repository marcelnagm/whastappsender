@extends('layouts.app-master')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-11">
        
        <div class="card shadow-sm border-0" style="height: 80vh; min-height: 600px; border-radius: 8px; overflow: hidden;">
            <div class="row g-0 h-100">
                
                <div class="col-4 border-end d-flex flex-column bg-white h-100">
                    <div class="p-3 bg-light border-bottom d-flex align-items-center justify-content-between">
                        <div>
                            <i class="bi bi-chat-left-dots fs-5 me-2 text-primary"></i>
                            <strong class="small text-uppercase tracking-wider">Conversas</strong>
                        </div>
                        <a href="{{ route('chat.index', ['refresh' => 1]) }}" class="btn btn-sm btn-outline-primary border-0 shadow-none" title="Atualizar Lista">
                            <i class="bi bi-arrow-clockwise"></i>
                        </a>
                    </div>
                    
                    <div class="p-2 border-bottom">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text bg-light border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" class="form-control bg-light border-start-0 shadow-none" placeholder="Buscar no espelho...">
                        </div>
                    </div>

                    <div class="overflow-auto flex-grow-1 chat-list">
                        @forelse($chats as $chat)
                        @php $jid = $chat['remoteJid']; $name = !empty($chat['pushName']) ? $chat['pushName'] : explode('@', $jid)[0]; @endphp
                        <div class="p-3 border-bottom list-group-item-action cursor-pointer d-flex align-items-center transition-all" 
                             onclick="loadMessages('{{ $jid }}', '{{ $name }}', this)" 
                             style="cursor: pointer;">
                            
                            <div class="position-relative">
                                @if(!empty($chat['profilePicUrl']))
                                    <img src="{{ $chat['profilePicUrl'] }}" class="rounded-circle me-3 border" width="48" height="48" onerror="this.src='https://ui-avatars.com/api/?name={{ urlencode($name) }}&background=random';">
                                @else
                                    <div class="rounded-circle me-3 bg-secondary d-flex align-items-center justify-content-center text-white" style="width: 48px; height: 48px;">
                                        <i class="bi bi-person-fill fs-4"></i>
                                    </div>
                                @endif
                            </div>

                            <div class="flex-grow-1 overflow-hidden">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0 small fw-bold text-truncate text-dark">{{ $name }}</h6>
                                    <small class="text-muted" style="font-size: 0.65rem;">
                                        {{ \Carbon\Carbon::parse($chat['updatedAt'])->format('H:i') }}
                                    </small>
                                </div>
                                <div class="text-muted small text-truncate" style="font-size: 0.75rem;">
                                    {{ $jid }}
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="p-5 text-center text-muted">
                            <i class="bi bi-chat-quote fs-1 d-block mb-2 opacity-25"></i>
                            <p class="small">Nenhum chat disponível.</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                <div class="col-8 d-flex flex-column h-100" style="background-color: #f0f2f5;">
                    
                    <div id="chat-header" class="p-3 bg-white border-bottom d-flex align-items-center justify-content-between shadow-sm z-1">
                        <div class="d-flex align-items-center">
                            <div id="header-avatar" class="me-2">
                                <i class="bi bi-whatsapp fs-3 text-success"></i>
                            </div>
                            <div>
                                <div id="header-name" class="fw-bold small leading-1 text-dark">Espelho de Conversa</div>
                                <span id="header-status" class="text-muted" style="font-size: 0.7rem;">Selecione um contato para visualizar</span>
                            </div>
                        </div>
                        <div class="dropdown">
                            <i class="bi bi-three-dots-vertical text-muted cursor-pointer" data-bs-toggle="dropdown"></i>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item small" href="#" onclick="location.reload()"><i class="bi bi-arrow-repeat me-2"></i>Recarregar</a></li>
                            </ul>
                        </div>
                    </div>

                    <div id="messages-container" class="flex-grow-1 p-4 overflow-auto d-flex flex-column" 
                         style="background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); background-blend-mode: overlay; background-color: #e5ddd5;">
                        
                        <div class="text-center my-auto">
                            <div class="bg-white d-inline-block px-4 py-2 rounded-pill shadow-sm border small text-muted">
                                <i class="bi bi-lock-fill me-1"></i> Selecione uma conversa para iniciar o espelhamento.
                            </div>
                        </div>
                    </div>

                    <div class="p-3 bg-light border-top">
                        <form id="chat-form" onsubmit="handleSendMessage(event)">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text bg-white border-0"><i class="bi bi-emoji-smile fs-5 text-muted"></i></span>
                                
                                <input type="hidden" id="active-jid-input">
                                <input type="text" 
                                       id="message-input" 
                                       class="form-control border-0 bg-white shadow-none py-2" 
                                       placeholder="Digite uma mensagem..." 
                                       autocomplete="off"
                                       disabled>

                                <button class="btn btn-primary px-3 shadow-sm" type="submit" id="btn-send" disabled>
                                    <i class="bi bi-send"></i>
                                </button>
                            </div>
                        </form>
                    </div>

                </div>
            </div>
        </div>

    </div>
</div>

<style>
    .cursor-pointer { cursor: pointer; }
    .list-group-item-action:hover { background-color: #f8f9fa; }
    .active-chat { background-color: #e9ecef !important; border-left: 4px solid #0d6efd !important; }
    .chat-list::-webkit-scrollbar { width: 5px; }
    .chat-list::-webkit-scrollbar-thumb { background: #dee2e6; border-radius: 10px; }
    #messages-container::-webkit-scrollbar { width: 6px; }
    #messages-container::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.1); border-radius: 10px; }
    .transition-all { transition: all 0.2s ease; }
</style>
@endsection

@section('js')
<script>
let currentActiveJid = '';

/**
 * Carrega as mensagens e libera o campo de texto
 */
function loadMessages(jid, name, element) {
    const container = document.getElementById('messages-container');
    const headerName = document.getElementById('header-name');
    const headerStatus = document.getElementById('header-status');
    const messageInput = document.getElementById('message-input');
    const btnSend = document.getElementById('btn-send');
    
    // Atualiza Estado Global e UI
    currentActiveJid = jid;
    document.getElementById('active-jid-input').value = jid;
    
    document.querySelectorAll('.list-group-item-action').forEach(el => el.classList.remove('active-chat'));
    element.classList.add('active-chat');
    
    headerName.innerText = name;
    headerStatus.innerText = 'Online (Modo Espelho)';
    
    // Habilita o Input
    messageInput.disabled = false;
    btnSend.disabled = false;
    messageInput.focus();

    container.innerHTML = '<div class="text-center mt-5"><div class="spinner-border text-primary"></div></div>';

    fetch('{{ route("chat.show") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ jid: jid })
    })
    .then(response => response.text())
    .then(html => {
        container.innerHTML = html;
        scrollToBottom();
    })
    .catch(err => {
        container.innerHTML = '<div class="p-3 text-danger text-center">Erro ao carregar histórico.</div>';
    });
}

/**
 * Gerencia o envio da mensagem
 */
function handleSendMessage(event) {
    event.preventDefault();
    
    const input = document.getElementById('message-input');
    const message = input.value.trim();
    
    if (!message || !currentActiveJid) return;

    // Limpa input imediatamente (UX Otimista)
    input.value = '';

    fetch('{{ route("chat.send") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ 
            jid: currentActiveJid, 
            message: message 
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Recarrega o container para atualizar o Redis e ver a nova bolha
            refreshMessages();
        } else {
            alert('Falha ao enviar: ' + (data.error || 'Erro desconhecido'));
        }
    })
    .catch(err => console.error('Erro na requisição:', err));
}

function refreshMessages() {
    if (!currentActiveJid) return;
    
    fetch('{{ route("chat.show") }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ jid: currentActiveJid })
    })
    .then(response => response.text())
    .then(html => {
        document.getElementById('messages-container').innerHTML = html;
        scrollToBottom();
    });
}

function scrollToBottom() {
    const container = document.getElementById('messages-container');
    container.scrollTop = container.scrollHeight;
}
</script>
@endsection