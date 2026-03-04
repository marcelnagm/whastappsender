@extends('layouts.app-master')

@section('template_title')
Editar Item: {{ $campaignItem->name }}
@endsection

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            {{-- Header e Breadcrumb --}}
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-1">
                            <li class="breadcrumb-item"><a href="{{ route('campaigns.index') }}">Campanhas</a></li>
                            <li class="breadcrumb-item active">Editar Item</li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-0 text-gray-800 fw-bold">Configurar Mensagem</h1>
                </div>
                <a href="{{ route('campaign-items.index') }}" class="btn btn-outline-secondary shadow-sm">
                    <i class="bi bi-arrow-left"></i> Voltar
                </a>
            </div>

            @include('layouts.partials.messages')

            {{-- Alerta de Bloqueio Operacional --}}
            @if($campaignItem->campaign && $campaignItem->campaign->status === 'running')
            <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
                <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
                <div>
                    <strong>Campanha em Execução!</strong><br>
                    Esta mensagem pertence a uma campanha que está ativa nos mineradores. Alterar o conteúdo agora pode causar disparos inconsistentes.
                </div>
            </div>
            @endif

            <div class="row">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm rounded-3">
                        <div class="card-body p-4">
                            @php
                            // Lógica para definir se é criação ou edição
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
                                    {{ Form::label('name', 'Identificação do Item', ['class' => 'form-label fw-bold']) }}
                                    {{ Form::text('name', $campaignItem->name, ['class' => 'form-control' . ($errors->has('name') ? ' is-invalid' : ''), 'required']) }}
                                    <small class="text-muted">Nome interno para controle de lotes.</small>
                                </div>

                                <div class="mb-4">
                                    {{ Form::label('campaign_id', 'Vincular à Campanha', ['class' => 'form-label fw-bold']) }}
                                    {{ Form::select('campaign_id', $campaigns, $campaignItem->campaign_id, ['class' => 'form-select', 'required']) }}
                                </div>

                                <div class="mb-4">
                                    {{ Form::label('image', 'URL da Imagem/Mídia', ['class' => 'form-label fw-bold']) }}
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-link-45deg"></i></span>
                                        {{ Form::text('image', $campaignItem->image, ['class' => 'form-control', 'id' => 'image_url', 'placeholder' => 'https://...']) }}
                                    </div>
                                </div>

                                <div class="mb-4">
                                    {{ Form::label('text', 'Conteúdo da Mensagem', ['class' => 'form-label fw-bold']) }}
                                    {{ Form::textarea('text', $campaignItem->text, ['class' => 'form-control', 'id' => 'message_text', 'rows' => '6']) }}
                                    <div class="d-flex justify-content-between mt-2">
                                        <div class="btn-group border shadow-sm">
                                            <button type="button" class="btn btn-sm btn-light" onclick="insertFormat('*')"><b>B</b></button>
                                            <button type="button" class="btn btn-sm btn-light" onclick="insertFormat('_')"><i>I</i></button>
                                        </div>
                                        <small id="char_count" class="text-muted fw-bold">0 caracteres</small>
                                    </div>
                                </div>

                                <div class="d-grid gap-2">
                                    <button type="submit" class="btn btn-primary btn-lg shadow" {{ ($campaignItem->campaign && $campaignItem->campaign->status === 'running') ? 'disabled' : '' }}>
                                        <i class="bi bi-cloud-upload"></i> Salvar Alterações
                                    </button>
                                    @if($campaignItem->campaign && $campaignItem->campaign->status === 'running')
                                    <small class="text-danger text-center font-italic">Desative a campanha para permitir a edição.</small>
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
                                        <img id="preview_image_src" src="" class="img-fluid rounded-2 w-100 shadow-sm">
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
        const $msgInput = $('#message_text'),
            $imgInput = $('#image_url'),
            $previewText = $('#preview_text_content'),
            $previewImg = $('#preview_image_src'),
            $previewImgContainer = $('#preview_image_container'),
            $charCount = $('#char_count');

        function updatePreview() {
            let text = $msgInput.val(),
                url = $imgInput.val();
            $previewText.text(text || "Visualização da mensagem...");
            $charCount.text(text.length + " caracteres");

            if (url && url.length > 5) {
                $previewImg.attr('src', url).on('load', () => $previewImgContainer.removeClass('d-none')).on('error', () => $previewImgContainer.addClass('d-none'));
            } else {
                $previewImgContainer.addClass('d-none');
            }
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
        updatePreview();
    });
</script>
@endsection