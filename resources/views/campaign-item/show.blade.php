@extends('layouts.app-master')

@section('template_title')
    Detalhes: {{ $campaignItem->name }}
@endsection

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h1 class="h3 mb-0 text-gray-800"><i class="bi bi-info-circle-fill text-primary"></i> Detalhes do Item</h1>
                    <p class="text-muted small">Revise a mídia e o conteúdo antes de iniciar o processo de geração.</p>
                </div>
                <div>
                    <a class="btn btn-outline-secondary shadow-sm me-2" href="{{ route('campaign-items.index') }}">
                        <i class="bi bi-arrow-left"></i> Voltar
                    </a>
                    <a class="btn btn-success shadow-sm" href="{{ route('campaign-items.edit', $campaignItem->id) }}">
                        <i class="bi bi-pencil"></i> Editar
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-header bg-white py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Informações Técnicas</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-borderless mb-0">
                                <tbody class="align-middle">
                                    <tr class="border-bottom">
                                        <td class="ps-4 fw-bold text-muted" style="width: 30%;">Identificação:</td>
                                        <td class="pe-4 text-dark">{{ $campaignItem->name }}</td>
                                    </tr>
                                    <tr class="border-bottom text-muted">
                                        <td class="ps-4 fw-bold">Campanha Pai:</td>
                                        <td class="pe-4">
                                            <span class="badge bg-secondary opacity-75">
                                                {{ $campaignItem->campaign->name ?? 'Sem Campanha' }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr class="border-bottom">
                                        <td class="ps-4 fw-bold text-muted">Responsável:</td>
                                        <td class="pe-4">{{ $campaignItem->user->name ?? 'ID: ' . $campaignItem->user_id }}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted">URL da Mídia:</td>
                                        <td class="pe-4">
                                            @if($campaignItem->image)
                                                <a href="{{ $campaignItem->image }}" target="_blank" class="text-truncate d-inline-block small" style="max-width: 250px;">
                                                    {{ $campaignItem->image }} <i class="bi bi-box-arrow-up-right"></i>
                                                </a>
                                            @else
                                                <span class="text-muted italic small">Nenhuma mídia anexada</span>
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="d-grid gap-2">
                        <a href="{{ route('campaign-items.send', $campaignItem->id) }}" class="btn btn-primary btn-lg shadow">
                            <i class="bi bi-send-check"></i> Enviar para Fila de Disparo agora
                        </a>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card border-0 shadow-lg overflow-hidden" style="border-radius: 20px;">
                        <div class="p-3 text-white d-flex align-items-center" style="background-color: #075e54;">
                            <i class="bi bi-arrow-left me-3"></i>
                            <div class="rounded-circle bg-secondary me-3" style="width: 38px; height: 38px; display: flex; align-items: center; justify-content: center;">
                                <i class="bi bi-person-fill text-white fs-5"></i>
                            </div>
                            <span class="fw-bold">Visualização do Cliente</span>
                        </div>

                        <div class="p-4" style="background-color: #e5ddd5; background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); min-height: 400px; background-size: contain;">
                            <div class="bg-white p-2 rounded-3 shadow-sm position-relative" style="max-width: 90%; background-color: #dcf8c6 !important; margin-left: auto;">
                                
                                @if($campaignItem->image)
                                    <div class="mb-2">
                                        @if($campaignItem->imageType() == 'image')
                                            <img src="{{ $campaignItem->image }}" class="img-fluid rounded-2 shadow-sm w-100" style="max-height: 350px; object-fit: cover;"> 
                                        @elseif($campaignItem->imageType() == 'video')
                                            <video controls src="{{ $campaignItem->image }}" class="img-fluid rounded-2 shadow-sm w-100"></video> 
                                        @endif
                                    </div>
                                @endif

                                <div class="px-2 pt-1 pb-4">
                                    <p class="mb-0 text-break" style="white-space: pre-wrap; color: #111; font-size: 0.95rem;">{!! nl2br(e($campaignItem->text)) !!}</p>
                                </div>

                                <div class="position-absolute bottom-0 end-0 pe-2 pb-1 text-muted d-flex align-items-center" style="font-size: 0.65rem;">
                                    <span>{{ now()->format('H:i') }}</span>
                                    <i class="bi bi-check2-all ms-1 text-primary" style="font-size: 0.85rem;"></i>
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
    /* Custom styles for WhatsApp Bubble look */
    .message-bubble::after {
        content: "";
        position: absolute;
        right: -10px;
        top: 0;
        border: 10px solid transparent;
        border-top-color: #dcf8c6;
    }
    .x-small { font-size: 0.75rem; }
</style>
@endsection