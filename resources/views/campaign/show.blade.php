@extends('layouts.app-master')

@section('template_title', 'Campaign details: ' . $campaign->name)

@section('content')
<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white overflow-hidden">
                <div class="card-body p-4 position-relative">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h6 class="text-uppercase opacity-75 small fw-bold mb-1">Campaign overview</h6>
                            <h1 class="display-6 fw-bold mb-2">{{ $campaign->name }}</h1>
                            <div class="d-flex gap-3">
                                <span class="badge bg-white text-primary px-3 py-2 rounded-pill">
                                    <i class="bi bi-calendar3 me-1"></i> Created: {{ $campaign->created_at->format('d/m/Y') }}
                                </span>
                                <span class="badge bg-white text-primary px-3 py-2 rounded-pill">
                                    <i class="bi bi-person me-1"></i> Owner: {{ $campaign->user->name ?? 'System' }}
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4 text-md-end mt-3 mt-md-0">
                            @php $overallRate = $campaign->getSuccessRate(); @endphp
                            <div class="d-inline-block text-center p-3 rounded-4 bg-white bg-opacity-10 border border-white border-opacity-25">
                                <h2 class="mb-0 fw-bold">{{ $overallRate }}%</h2>
                                <small class="text-uppercase x-small">Overall success</small>
                            </div>
                        </div>
                    </div>
                    <i class="bi bi-megaphone position-absolute end-0 bottom-0 opacity-10 m-n3" style="font-size: 8rem;"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-3">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white py-3 border-0">
                    <h6 class="mb-0 fw-bold">Settings</h6>
                </div>
                <div class="card-body pt-0">
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted small">Total items:</span>
                            <span class="fw-bold">{{ $campaign->campaignItems->count() }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted small">Status:</span>
                            <span class="badge bg-success">Active</span>
                        </li>
                    </ul>
                    <div class="d-grid gap-2 mt-4">
                        <a href="{{ route('campaigns.edit', $campaign->id) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-pencil me-1"></i> Edit campaign
                        </a>
                        <a href="{{ route('campaigns.index') }}" class="btn btn-light btn-sm">
                            <i class="bi bi-arrow-left me-1"></i> Back to list
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @include('campaign-item.list')
    </div>
</div>

<style>
    /* Tooltip + utilities */
    .media-tooltip-container { position: relative; display: inline-block; cursor: pointer; }
    .media-tooltip-content {
        visibility: hidden; position: absolute; z-index: 10001; bottom: 125%; left: 50%; transform: translateX(-50%);
        width: 160px; background: #fff; border-radius: 8px; box-shadow: 0 5px 15px rgba(0,0,0,0.2); opacity: 0; transition: 0.2s;
    }
    .media-tooltip-content img { width: 100%; border-radius: 8px; }
    .media-tooltip-container:hover .media-tooltip-content { visibility: visible; opacity: 1; }
    .x-small { font-size: 0.65rem; }
</style>
@endsection