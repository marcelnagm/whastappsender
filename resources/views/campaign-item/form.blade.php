@extends('layouts.app-master')

@section('template_title')
Edit item: {{ $campaignItem->name }}
@endsection

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            {{-- Header & breadcrumb --}}
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-1">
                            <li class="breadcrumb-item"><a href="{{ route('campaigns.index') }}">Campaigns</a></li>
                            <li class="breadcrumb-item active">Edit item</li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-0 text-gray-800 fw-bold">Configure message</h1>
                </div>
                <a href="{{ route('campaign-items.index') }}" class="btn btn-outline-secondary shadow-sm">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>

            @include('layouts.partials.messages')

            {{-- Operational lock alert --}}
            @if($campaignItem->campaign && $campaignItem->campaign->status === 'running')
            <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                <div>
                    <strong>Campaign is running!</strong><br>
                    This message belongs to an active campaign. Editing now may cause inconsistent sends.
                </div>
            </div>
            @endif

            <div class="row">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-body p-4">
                            @php
                            // Create vs edit routing
                            $isEdit = !empty($campaignItem->id);
                            $route = $isEdit
                            ? route('campaign-items.update', ['campaign_item' => $campaignItem->id])
                            : route('campaign-items.store');
                            @endphp

                            <form method="POST" action="{{ $route }}" role="form" enctype="multipart/form-data">
                                @csrf
                                @if($isEdit)
                                @method('PATCH')
                                @endif

                                <div class="mb-4">
                                    {{ Form::label('name', 'Item name', ['class' => 'form-label fw-bold']) }}
                                    {{ Form::text('name', $campaignItem->name, ['class' => 'form-control' . ($errors->has('name') ? ' is-invalid' : ''), 'required']) }}
                                    <small class="text-muted">Internal label for batch control.</small>
                                </div>

                                <div class="mb-4">
                                    {{ Form::label('campaign_id', 'Link to campaign', ['class' => 'form-label fw-bold']) }}
                                    {{ Form::select('campaign_id', $campaigns, $campaignItem->campaign_id, ['class' => 'form-select', 'required']) }}
                                </div>

                                <div class="mb-4">
                                    <div class="form-check form-switch">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            id="welcome_enabled"
                                            name="welcome_enabled"
                                            value="1"
                                            {{ old('welcome_enabled', $campaignItem->welcome_enabled) ? 'checked' : '' }}>
                                        <label class="form-check-label fw-bold" for="welcome_enabled">
                                            Welcome message enabled
                                        </label>
                                    </div>
                                    <small class="text-muted">Enable to mark this item as the welcome message.</small>
                                </div>

                                {{-- Media URL vs upload --}}
                                <div class="mb-4">
                                    <label class="form-label fw-bold d-flex justify-content-between">
                                        Media source
                                        <div class="form-check form-switch ms-3">
                                            <input class="form-check-input" type="checkbox" id="toggle_upload" name="use_upload" value="1" {{ $campaignItem->image && !filter_var($campaignItem->image, FILTER_VALIDATE_URL) ? 'checked' : '' }}>
                                            <label class="form-check-label text-muted" style="font-size: 0.8rem;" for="toggle_upload">Upload (S3)</label>
                                        </div>
                                    </label>

                                    {{-- Option 1: external URL --}}
                                    <div id="url_container" class="{{ $campaignItem->image && !filter_var($campaignItem->image, FILTER_VALIDATE_URL) ? 'd-none' : '' }}">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                                            {{ Form::text('image_url', filter_var($campaignItem->image, FILTER_VALIDATE_URL) ? $campaignItem->image : '', ['class' => 'form-control', 'id' => 'image_url', 'placeholder' => 'https://...']) }}
                                        </div>
                                    </div>

                                    {{-- Option 2: file upload --}}
                                    <div id="upload_container" class="{{ $campaignItem->image && !filter_var($campaignItem->image, FILTER_VALIDATE_URL) ? '' : 'd-none' }}">
                                        <div class="input-group">
                                            <span class="input-group-text"><i class="bi bi-paperclip"></i></span>
                                            <input type="file" name="file_upload" id="file_upload" class="form-control" accept="{{ '.' . implode(',.', array_keys(\App\Models\CampaignItem::MEDIA_TYPES)) }}">
                                        </div>
                                        <div id="file_upload_error" class="small text-danger mt-1 d-none"></div>
                                        <div class="form-text">Image, video, audio (sent as a voice note, without the text), PDF or Office document, up to 16 MB.</div>
                                        @if($campaignItem->image && !filter_var($campaignItem->image, FILTER_VALIDATE_URL))
                                        <div class="mt-2 small text-success">
                                            <i class="bi bi-check-circle"></i> Current file: <code>{{ basename($campaignItem->image) }}</code>
                                        </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="mb-4">
                                    {{ Form::label('text', 'Message body', ['class' => 'form-label fw-bold']) }}
                                    {{ Form::textarea('text', $campaignItem->text, ['class' => 'form-control', 'id' => 'message_text', 'rows' => '6']) }}
                                    <div class="d-flex justify-content-between mt-2">
                                        <div class="btn-group border shadow-sm">
                                            <button type="button" class="btn btn-sm btn-light" onclick="insertFormat('*')"><b>B</b></button>
                                            <button type="button" class="btn btn-sm btn-light" onclick="insertFormat('_')"><i>I</i></button>
                                        </div>
                                        <small id="char_count" class="text-muted fw-bold">0 characters</small>
                                    </div>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg shadow" {{ ($campaignItem->campaign && $campaignItem->campaign->status === 'running') ? 'disabled' : '' }}>
                                        <i class="bi bi-cloud-upload"></i> Save changes
                                    </button>
                                    @if($campaignItem->campaign && $campaignItem->campaign->status === 'running')
                                    <small class="text-danger text-center font-italic">Stop the campaign to allow editing.</small>
                                    @endif
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="sticky-top" style="top: 2rem;">
                        <div class="whatsapp-preview shadow-lg rounded-4 overflow-hidden border">
                            <div class="p-3 text-white d-flex align-items-center" style="background-color: #075e54;">
                                <i class="bi bi-person-circle fs-4 me-2"></i>
                                <span class="fw-bold">Preview WhatsApp</span>
                            </div>
                            <div class="whatsapp-body p-4" style="background-color: #e5ddd5; background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); min-height: 400px;">
                                <div class="message-bubble bg-white p-2 rounded-3 shadow-sm position-relative" style="max-width: 90%; margin-left: auto; background-color: #dcf8c6 !important;">
                                    <div id="preview_image_container" class="mb-2 d-none">
                                        <img id="preview_image_src" src="" class="img-fluid rounded-2 w-100 shadow-sm d-none" alt="">
                                        <video id="preview_video_src" controls preload="metadata" class="w-100 rounded-2 shadow-sm d-none"></video>
                                        <audio id="preview_audio_src" controls preload="metadata" class="w-100 d-none"></audio>
                                        <div id="preview_doc" class="d-none">
                                            <div class="d-flex align-items-center p-2 rounded-2 border bg-light">
                                                <i class="bi bi-file-earmark-text fs-1 text-danger me-2"></i>
                                                <div class="text-truncate">
                                                    <div id="preview_doc_name" class="fw-bold small text-truncate"></div>
                                                    <div id="preview_doc_meta" class="text-muted" style="font-size: .75rem;"></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div id="preview_audio_note" class="small text-muted mt-1 d-none"><i class="bi bi-mic me-1"></i>Audio is sent as a voice note: the message text is not sent with it.</div>
                                    </div>
                                    <div class="px-2 pt-1 pb-4">
                                        <span id="preview_text_content" class="text-break" style="white-space: pre-wrap; font-size: 0.9rem;"></span>
                                    </div>
                                    <div class="position-absolute bottom-0 end-0 pe-2 pb-1 text-muted small">
                                        {{ now()->format('H:i') }} <i class="bi bi-check2-all text-primary"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .message-bubble::after {
        content: "";
        position: absolute;
        right: -10px;
        top: 0;
        border: 10px solid transparent;
        border-top-color: #dcf8c6;
    }
</style>
@endsection

@section('js')
<script>
    $(document).ready(function() {
        const KINDS = @json(\App\Models\CampaignItem::kindsByExtension()),
            MAX_UPLOAD = {{ \App\Models\CampaignItem::MAX_UPLOAD_KB }} * 1024, // same limit as the server
            $msgInput = $('#message_text'),
            $imgInput = $('#image_url'),
            $fileInput = $('#file_upload'),
            $toggle = $('#toggle_upload'),
            $previewText = $('#preview_text_content'),
            $container = $('#preview_image_container'),
            $img = $('#preview_image_src'),
            $video = $('#preview_video_src'),
            $audio = $('#preview_audio_src'),
            $doc = $('#preview_doc'),
            $charCount = $('#char_count');

        let objectUrl = null, shown = null;

        function releaseObjectUrl() {
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        }

        function extOf(name) {
            const m = /\.([a-z0-9]+)$/i.exec(name || '');
            return m ? m[1].toLowerCase() : '';
        }

        function human(bytes) {
            return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(bytes / 1024)) + ' KB';
        }

        // What the preview should show: the chosen file (upload mode) or the typed URL (link mode)
        function mediaSource() {
            if ($toggle.is(':checked')) {
                const file = $fileInput[0].files && $fileInput[0].files[0];
                const kind = file && KINDS[extOf(file.name)];
                if (!file || !kind || file.size > MAX_UPLOAD) return null;
                if (!objectUrl || $fileInput.data('previewed') !== file) {
                    releaseObjectUrl();
                    objectUrl = URL.createObjectURL(file);
                    $fileInput.data('previewed', file);
                }
                return {kind: kind, src: objectUrl, name: file.name, size: file.size};
            }
            const url = ($imgInput.val() || '').trim();
            if (url.length <= 5) return null;
            let path = url;
            try { path = new URL(url).pathname; } catch (e) {}
            // Unknown extension: assume an image (as the list screen does); the image is hidden if it does not load
            return {kind: KINDS[extOf(path)] || 'image', src: url, name: decodeURIComponent(path.split('/').pop() || url), size: null};
        }

        function checkFile() {
            const file = $fileInput[0].files && $fileInput[0].files[0];
            let msg = '';
            if (file && !KINDS[extOf(file.name)]) msg = 'This file type is not supported.';
            else if (file && file.size > MAX_UPLOAD) msg = 'The file is larger than ' + human(MAX_UPLOAD) + '.';
            $('#file_upload_error').text(msg).toggleClass('d-none', !msg);
            $fileInput.toggleClass('is-invalid', !!msg);
        }

        function hideMedia() {
            $container.addClass('d-none');
            $img.add($video).add($audio).add($doc).addClass('d-none');
            $('#preview_audio_note').addClass('d-none');
            // stop playback and free the old source
            $video[0].pause(); $audio[0].pause();
            $img.removeAttr('src'); $video.removeAttr('src'); $audio.removeAttr('src');
            $video[0].load(); $audio[0].load();
            shown = null;
        }

        function showMedia(m) {
            const key = m.kind + '|' + m.src;
            if (shown === key) return;       // already on screen
            hideMedia();
            shown = key;
            if (m.kind === 'image') {
                $img.removeClass('d-none').attr('src', m.src);
                $container.addClass('d-none'); // revealed by the load handler, hidden again on error
            } else if (m.kind === 'video') {
                $video.removeClass('d-none').attr('src', m.src);
                $container.removeClass('d-none');
            } else if (m.kind === 'audio') {
                $audio.removeClass('d-none').attr('src', m.src);
                $('#preview_audio_note').removeClass('d-none');
                $container.removeClass('d-none');
            } else {
                $('#preview_doc_name').text(m.name);
                $('#preview_doc_meta').text((extOf(m.name) || 'file').toUpperCase() + (m.size ? ' · ' + human(m.size) : ''));
                $doc.removeClass('d-none');
                $container.removeClass('d-none');
            }
        }

        // The image is shown only once it has loaded, and hidden again if it cannot be displayed
        $img.on('load', () => $container.removeClass('d-none'))
            .on('error', () => { if ($img.attr('src')) $container.addClass('d-none'); });
        $video.on('error', () => { if ($video.attr('src')) $container.addClass('d-none'); });
        $audio.on('error', () => { if ($audio.attr('src')) $container.addClass('d-none'); });

        function updatePreview() {
            const text = $msgInput.val();
            $previewText.text(text || "Message preview...");
            $charCount.text(text.length + " characters");

            const m = mediaSource();
            if (m) showMedia(m); else hideMedia();
        }

        window.insertFormat = function(char) {
            const el = $msgInput[0],
                start = el.selectionStart,
                end = el.selectionEnd,
                text = el.value;
            el.value = text.substring(0, start) + char + text.substring(start, end) + char + text.substring(end);
            updatePreview();
            el.focus();
        };

        $msgInput.on('input propertychange', updatePreview);
        $imgInput.on('input propertychange', updatePreview);
        $fileInput.on('change', function() { checkFile(); updatePreview(); });

        $toggle.on('change', function() {
            if ($(this).is(':checked')) {
                $('#url_container').addClass('d-none');
                $('#upload_container').removeClass('d-none');
                $('#image_url').val(''); // Clear URL so upload takes priority
            } else {
                $('#url_container').removeClass('d-none');
                $('#upload_container').addClass('d-none');
                $fileInput.val('');
                checkFile();
            }
            updatePreview();
        });

        $(window).on('beforeunload', releaseObjectUrl);
        updatePreview();
    });
</script>

@endsection
