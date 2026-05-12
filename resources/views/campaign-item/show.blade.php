@extends('layouts.app-master')

@section('template_title')
Detalhes: {{ $campaignItem->name }}
@endsection

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-end mb-4">
                <div>
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb mb-1">
                            <li class="breadcrumb-item small text-uppercase fw-bold"><a href="{{ route('campaign-items.index') }}" class="text-decoration-none">Campaign items</a></li>
                            <li class="breadcrumb-item small text-uppercase fw-bold active">Details</li>
                        </ol>
                    </nav>
                    <h1 class="h3 mb-0 text-gray-800 fw-bold">
                        <span class="bg-primary bg-opacity-10 p-2 rounded-3 me-2">
                            <i class="bi bi-info-circle-fill text-primary"></i>
                        </span>
                        {{ $campaignItem->name }}
                    </h1>
                </div>
                <div class="d-flex gap-2">
                    <a class="btn btn-light border px-3 fw-bold shadow-sm" href="{{ route('campaign-items.index') }}">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </a>
                    <a class="btn btn-success px-3 fw-bold shadow-sm" href="{{ route('campaign-items.edit', $campaignItem->id) }}">
                        <i class="bi bi-pencil me-1"></i> Edit
                    </a>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm mb-4" style="border-radius: 15px;">
                        <div class="card-header bg-white py-3 border-bottom-0">
                            <h6 class="m-0 fw-bold text-primary text-uppercase small" style="letter-spacing: 1px;">Item details</h6>
                        </div>
                        <div class="card-body p-0">
                            <table class="table table-hover mb-0">
                                <tbody class="align-middle">
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted small" style="width: 35%;">Campaign:</td>
                                        <td class="pe-4">
                                            <span class="badge bg-soft-primary text-primary fw-bold">
                                                {{ $campaignItem->campaign->name ?? 'No campaign' }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted small">Created by:</td>
                                        <td class="pe-4 text-dark small">{{ $campaignItem->user->name ?? 'N/A' }}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted small">Media:</td>
                                        <td class="pe-4">
                                            @if($campaignItem->image)
                                            <a href="{{ $campaignItem->image }}" target="_blank" class="btn btn-xs btn-outline-info rounded-pill px-3 py-1 fw-bold" style="font-size: 0.7rem;">
                                                <i class="bi bi-box-arrow-up-right me-1"></i> Open original link
                                            </a>
                                            @else
                                            <span class="text-muted italic small">Text only</span>
                                            @endif
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="ps-4 fw-bold text-muted small">Delivery rate:</td>
                                        <td class="pe-4">
                                            @php $rate = $campaignItem->getDeliveryRate(); @endphp
                                            <span class="fw-bold text-{{ $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger') }}">
                                                {{ $rate }}% (ACK)
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="card border-0 shadow-sm p-3 bg-light" style="border-radius: 15px;">
                        <div class="row g-2">
                            <div class="col-8">
                                <div class="btn-group w-100 shadow-sm">
                                    <a href="{{ route('campaign-items.send', $campaignItem->id) }}" class="btn btn-primary btn-lg fw-bold flex-grow-1">
                                        <i class="bi bi-send-check-fill me-2"></i> START SENDING
                                    </a>
                                    <button type="button" class="btn btn-primary btn-lg dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
                                        <span class="visually-hidden">Toggle Dropdown</span>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="border-radius: 12px;">
                                        <li>
                                            <h6 class="dropdown-header text-uppercase x-small fw-bold">Prep</h6>
                                        </li>
                                        <li><a class="dropdown-item rounded-2" href="{{ route('campaign-items.generateAll',$campaignItem->id) }}"><i class="bi bi-layers me-2 text-muted"></i> Generate for all</a></li>
                                        @if(Auth::user()->role === 'admin')
                                        <li><a class="dropdown-item rounded-2" href="{{ route('campaign-items.generate',$campaignItem->id) }}"><i class="bi bi-lightning-charge me-2 text-muted"></i> Generate test</a></li>
                                        @endif
                                    </ul>
                                </div>
                            </div>
                            <div class="col-4">
                                <a href="{{ route('whatsapp-jobs.index', $campaignItem->id) }}" class="btn btn-dark btn-lg w-100 fw-bold shadow-sm">
                                    <i class="bi bi-terminal-fill me-1"></i> LOGS
                                </a>
                            </div>
                        </div>
                        <p class="text-center text-muted x-small mt-3 mb-0 italic">
                            <i class="bi bi-info-circle me-1"></i> Generate queue items before starting sends.
                        </p>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="card border-0 shadow-lg overflow-hidden position-relative" style="border-radius: 25px; border: 8px solid #333 !important;">
                        <div class="p-3 text-white d-flex align-items-center justify-content-between" style="background-color: #075e54; z-index: 10;">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-arrow-left me-3 fs-5"></i>
                                <div class="rounded-circle bg-secondary me-2 d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                    <i class="bi bi-person-fill text-white fs-4"></i>
                                </div>
                                <div>
                                    <h6 class="mb-0 fw-bold">Your contact</h6>
                                    <span class="x-small opacity-75">online</span>
                                </div>
                            </div>
                            <div class="d-flex gap-3 fs-5 opacity-75">
                                <i class="bi bi-camera-video-fill"></i>
                                <i class="bi bi-telephone-fill"></i>
                                <i class="bi bi-three-dots-vertical"></i>
                            </div>
                        </div>

                        <div class="p-4 bg-whatsapp-chat" style="min-height: 480px; overflow-y: auto;">
                            <div class="whatsapp-bubble shadow-sm">
                                @if($campaignItem->image)
                                <div class="mb-2 bubble-media">
                                    @if($campaignItem->imageType() == 'image')
                                    <img src="{{ $campaignItem->image }}" class="img-fluid w-100 h-auto">
                                    @elseif($campaignItem->imageType() == 'video')
                                    <video controls src="{{ $campaignItem->image }}" class="w-100"></video>
                                    @endif
                                </div>
                                @endif

                                <div class="bubble-text px-2 pt-1 pb-4">
                                    <p class="mb-0 text-break">{!! nl2br(e($campaignItem->text)) !!}</p>
                                </div>

                                <div class="bubble-meta">
                                    <span>{{ now()->format('H:i') }}</span>
                                    <i class="bi bi-check2-all ms-1 text-primary"></i>
                                </div>
                            </div>
                        </div>

                        <div class="p-2 bg-light d-flex align-items-center border-top">
                            <i class="bi bi-emoji-smile fs-4 mx-2 text-muted"></i>
                            <div class="flex-grow-1 bg-white rounded-pill py-2 px-3 text-muted small">Message</div>
                            <i class="bi bi-mic-fill fs-4 mx-3 text-muted"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Chat background */
    .bg-whatsapp-chat {
        background-color: #e5ddd5;
        background-image: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');
        background-size: contain;
    }

    /* Message bubble */
    .whatsapp-bubble {
        max-width: 85%;
        margin-left: auto;
        background-color: #dcf8c6;
        border-radius: 12px 0px 12px 12px;
        position: relative;
        padding: 4px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15) !important;
    }

    .bubble-media img,
    .bubble-media video {
        border-radius: 10px 0 10px 10px;
        display: block;
    }

    .bubble-text p {
        color: #111;
        font-size: 0.93rem;
        line-height: 1.4;
        white-space: pre-wrap;
    }

    .bubble-meta {
        position: absolute;
        bottom: 4px;
        right: 8px;
        font-size: 0.65rem;
        color: rgba(0, 0, 0, 0.45);
        display: flex;
        align-items: center;
    }

    /* Utility */
    .x-small {
        font-size: 0.7rem;
    }

    .bg-soft-primary {
        background-color: #eef4ff;
    }

    .btn-xs {
        padding: 0.25rem 0.5rem;
        font-size: 0.75rem;
    }

    .italic {
        font-style: italic;
    }
</style>
@endsection