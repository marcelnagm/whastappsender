@extends('layouts.app-master')

@section('content')
<link rel="stylesheet" href="{{ url('assets/css/chat.css') }}?v=1">

@if ($instances->isEmpty())
    <div class="alert alert-warning">
        {{ __('You have no WhatsApp instance yet.') }}
        <a href="{{ route('instances.create') }}" class="alert-link">{{ __('Create one') }}</a>
    </div>
@else
<div id="wa-app"
     class="wa-app"
     data-csrf="{{ csrf_token() }}"
     data-instance="{{ $current->id }}"
     data-connected="{{ $current->isConnected() ? 1 : 0 }}"
     data-poll-messages="{{ config('chat.poll_messages_ms') }}"
     data-poll-chats="{{ config('chat.poll_chats_ms') }}"
     data-max-kb="{{ config('chat.max_upload_kb') }}"
     data-url-chats="{{ route('chat.api.chats') }}"
     data-url-open="{{ route('chat.api.open') }}"
     data-url-contacts="{{ route('chat.api.contacts') }}"
     data-url-sync="{{ route('chat.api.sync', $current->id) }}"
     data-url-sync-status="{{ route('chat.api.sync-status', $current->id) }}"
     data-url-chat-base="{{ url('chat/api/chats') }}"
     data-url-message-base="{{ url('chat/api/messages') }}"
     data-url-index="{{ route('chat.index') }}">

    {{-- ============ Sidebar ============ --}}
    <aside class="wa-side" id="wa-side">
        <header class="wa-side-header">
            <div class="wa-instance">
                <i class="bi bi-whatsapp"></i>
                @if ($instances->count() > 1)
                    <select id="wa-instance-select" class="form-select form-select-sm" aria-label="{{ __('Instance') }}">
                        @foreach ($instances as $ins)
                            <option value="{{ $ins->id }}" @if($ins->id === $current->id) {{ __('selected') }} @endif>
                                {{ $ins->name }} · {{ $ins->instance_name }}
                            </option>
                        @endforeach
                    </select>
                @else
                    <span class="wa-instance-name">{{ $current->name }} · {{ $current->instance_name }}</span>
                @endif
            </div>
            <div class="wa-side-actions">
                <button type="button" class="wa-icon-btn" id="wa-sync" title="{{ __('Import history') }}"><i class="bi bi-arrow-repeat"></i></button>
                <button type="button" class="wa-icon-btn" id="wa-new" title="{{ __('New chat') }}"><i class="bi bi-chat-left-text"></i></button>
            </div>
        </header>

        <div class="wa-search">
            <i class="bi bi-search"></i>
            <input type="search" id="wa-search" placeholder="{{ __('Search or start a new chat') }}" autocomplete="off">
        </div>

        <div id="wa-newchat" class="wa-newchat d-none">
            <div class="wa-newchat-title">{{ __('New chat') }}</div>
            <input type="search" id="wa-newchat-q" class="form-control form-control-sm" placeholder="{{ __('Name or phone (with country code)') }}" autocomplete="off">
            <div id="wa-newchat-results" class="wa-newchat-results"></div>
            <div class="wa-newchat-hint">{{ __('Example: 5595981110695') }}</div>
        </div>

        <div id="wa-sync-bar" class="wa-sync-bar d-none" role="status" aria-live="polite"></div>

        <div id="wa-chat-list" class="wa-chat-list" role="list"></div>
        <div id="wa-chat-empty" class="wa-empty-list d-none">{{ __('No conversations yet.') }}</div>
    </aside>

    {{-- ============ Conversation ============ --}}
    <section class="wa-main" id="wa-main">
        <div id="wa-placeholder" class="wa-placeholder">
            <i class="bi bi-whatsapp"></i>
            <h2>{{ __('WhatsApp CRM') }}</h2>
            <p>{{ __('Select a conversation to start.') }}</p>
        </div>

        <div id="wa-conversation" class="wa-conversation d-none">
            <header class="wa-conv-header">
                <button type="button" class="wa-icon-btn wa-back" id="wa-back" aria-label="{{ __('Back') }}"><i class="bi bi-arrow-left"></i></button>
                <div class="wa-avatar" id="wa-conv-avatar"></div>
                <div class="wa-conv-title" id="wa-conv-title-box">
                    <div class="wa-conv-name" id="wa-conv-name"></div>
                    <div class="wa-conv-sub" id="wa-conv-sub"></div>
                </div>
                <button type="button" class="wa-icon-btn" id="wa-info-btn" title="{{ __('Contact info') }}"><i class="bi bi-person-lines-fill"></i></button>
            </header>

            <div id="wa-messages" class="wa-messages">
                <div id="wa-load-more" class="wa-load-more d-none">
                    <button type="button" class="btn btn-sm btn-light">{{ __('Load older messages') }}</button>
                </div>
                <div id="wa-messages-inner"></div>
            </div>

            <button type="button" id="wa-scroll-down" class="wa-scroll-down d-none" aria-label="{{ __('Scroll to latest') }}">
                <i class="bi bi-chevron-double-down"></i><span id="wa-scroll-badge" class="d-none"></span>
            </button>

            <div id="wa-reply-bar" class="wa-reply-bar d-none">
                <div class="wa-reply-quote">
                    <div class="wa-reply-who" id="wa-reply-who"></div>
                    <div class="wa-reply-text" id="wa-reply-text"></div>
                </div>
                <button type="button" class="wa-icon-btn" id="wa-reply-cancel" aria-label="{{ __('Cancel') }}"><i class="bi bi-x-lg"></i></button>
            </div>

            <div id="wa-attach-bar" class="wa-attach-bar d-none">
                <div class="wa-attach-preview" id="wa-attach-preview"></div>
                <div class="wa-attach-name" id="wa-attach-name"></div>
                <button type="button" class="wa-icon-btn" id="wa-attach-cancel" aria-label="{{ __('Cancel') }}"><i class="bi bi-x-lg"></i></button>
            </div>

            <footer class="wa-composer">
                <div class="wa-emoji-wrap">
                    <button type="button" class="wa-icon-btn" id="wa-emoji-btn" title="{{ __('Emoji') }}"><i class="bi bi-emoji-smile"></i></button>
                    <div id="wa-emoji-panel" class="wa-emoji-panel d-none"></div>
                </div>
                <button type="button" class="wa-icon-btn" id="wa-attach-btn" title="{{ __('Attach') }}"><i class="bi bi-paperclip"></i></button>
                <input type="file" id="wa-file" class="d-none">

                <div id="wa-recording" class="wa-recording d-none">
                    <button type="button" class="wa-icon-btn text-danger" id="wa-rec-cancel" title="{{ __('Cancel') }}"><i class="bi bi-trash"></i></button>
                    <span class="wa-rec-dot"></span><span id="wa-rec-time">0:00</span>
                </div>

                <textarea id="wa-input" rows="1" placeholder="{{ __('Type a message') }}" maxlength="4096"></textarea>

                <button type="button" class="wa-send" id="wa-mic" title="{{ __('Voice message') }}"><i class="bi bi-mic-fill"></i></button>
                <button type="button" class="wa-send d-none" id="wa-send" title="{{ __('Send') }}"><i class="bi bi-send-fill"></i></button>
            </footer>
        </div>
    </section>

    {{-- ============ CRM side panel ============ --}}
    <aside class="wa-info d-none" id="wa-info">
        <header class="wa-info-header">
            <button type="button" class="wa-icon-btn" id="wa-info-close" aria-label="{{ __('Close') }}"><i class="bi bi-x-lg"></i></button>
            <span>{{ __('Contact info') }}</span>
        </header>
        <div class="wa-info-body" id="wa-info-body"></div>
    </aside>

    <div id="wa-toast" class="wa-toast d-none"></div>
</div>

{{-- Texts used by chat.js --}}
<script>
    window.WA_I18N = {
        today: @json(__('Today')),
        yesterday: @json(__('Yesterday')),
        you: @json(__('You')),
        photo: @json(__('Photo')),
        video: @json(__('Video')),
        audio: @json(__('Voice message')),
        document: @json(__('Document')),
        sticker: @json(__('Sticker')),
        contactCard: @json(__('Contact')),
        location: @json(__('Location')),
        deleted: @json(__('This message was deleted')),
        unsupported: @json(__('Message not supported here')),
        retry: @json(__('Failed to send. Click to retry')),
        reply: @json(__('Reply')),
        react: @json(__('React')),
        remove: @json(__('Delete for everyone')),
        confirmDelete: @json(__('Delete this message for everyone?')),
        copy: @json(__('Copy')),
        copied: @json(__('Copied')),
        syncWaiting: @json(__('Import queued, waiting for a queue worker…')),
        syncWorkerHint: @json(__('Still waiting. The import runs in the queue: start the worker (php artisan queue:work --queue=default,disparos,warmup).')),
        syncRunning: @json(__('Importing history…')),
        syncProgress: @json(__(':done of :total conversations · :messages messages')),
        syncDone: @json(__('History imported: :chats conversation(s), :messages message(s).')),
        syncFailed: @json(__('Import failed')),
        syncStalled: @json(__('The import stopped without finishing. Check the queue worker and try again.')),
        download: @json(__('Download')),
        fileTooBig: @json(__('File is too large.')),
        micDenied: @json(__('Microphone access denied.')),
        micUnsupported: @json(__('Your browser does not support audio recording.')),
        error: @json(__('Something went wrong. Try again.')),
        queued: @json(__('Import started. New conversations will appear shortly.')),
        noResults: @json(__('No results')),
        startChat: @json(__('Start a chat with')),
        inCrm: @json(__('In CRM')),
        notInCrm: @json(__('Not in CRM yet')),
        saveToCrm: @json(__('Save to CRM')),
        save: @json(__('Save')),
        name: @json(__('Name')),
        email: @json(__('Email')),
        phone: @json(__('Phone')),
        status: @json(__('Status')),
        score: @json(__('Score')),
        openContact: @json(__('Open contact')),
        editContact: @json(__('Edit contact')),
        optedOut: @json(__('Opted out (#sair)')),
        aiAuto: @json(__('AI auto-reply')),
        aiPaused: @json(__('Paused for this contact (human handling)')),
        aiActive: @json(__('Active')),
        aiOff: @json(__('Disabled in your profile')),
        pauseAi: @json(__('Pause AI here')),
        resumeAi: @json(__('Resume AI')),
        whatsappName: @json(__('WhatsApp name')),
        typing: @json(__('Type a message')),
    };
</script>
<script src="{{ url('assets/js/chat.js') }}?v=1"></script>
@endif
@endsection
