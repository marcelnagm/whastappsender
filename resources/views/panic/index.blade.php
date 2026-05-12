@extends('layouts.app-master')

@section('template_title', 'Crisis control center')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 mb-0 text-danger fw-bold">
            <span class="bg-danger bg-opacity-10 p-2 rounded-3 me-2">
                <i class="bi bi-exclamation-octagon-fill text-danger"></i>
            </span>
            Crisis control center
        </h1>
        <p class="text-muted">Immediately stop campaign sends and warmup processes.</p>
    </div>

    @include('layouts.partials.messages')

    <div class="row g-4">
        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5">
                    <h6 class="text-uppercase text-muted small fw-bold mb-3">Overall state</h6>
                    @if(Cache::get('system_panic_mode'))
                        <div class="display-4 text-danger mb-2"><i class="bi bi-pause-circle-fill"></i></div>
                        <span class="badge bg-danger px-3 py-2 rounded-pill">SISTEMA BLOQUEADO</span>
                    @else
                        <div class="display-4 text-success mb-2"><i class="bi bi-play-circle-fill"></i></div>
                        <span class="badge bg-success px-3 py-2 rounded-pill">OPERACIONAL</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 15px;">
                <div class="card-body d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold mb-2">Kill Switch</h5>
                        <p class="small text-muted">Pauses processing for <strong>campaigns</strong> and <strong>warmup</strong>. Items stay in the queue but are not sent.</p>
                    </div>
                    <form action="{{ route('admin.panic.toggle') }}" method="POST">
                        @csrf
                        <button type="submit" class="btn {{ Cache::get('system_panic_mode') ? 'btn-success' : 'btn-outline-danger' }} w-100 py-3 fw-bold rounded-pill">
                            <i class="bi {{ Cache::get('system_panic_mode') ? 'bi-play-fill' : 'bi-stop-fill' }} me-2"></i>
                            {{ Cache::get('system_panic_mode') ? 'RESUME ALL' : 'PAUSE ALL' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 border-danger border-opacity-10" style="border-radius: 15px;">
                <div class="card-body d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold text-danger mb-2">Flush: Campaigns</h5>
                        <p class="small text-muted">Permanently deletes all pending sends in the <code>disparos</code> queue.</p>
                    </div>
                    <form action="{{ route('admin.panic.clear') }}" method="POST" onsubmit="return confirm('WARNING: Clear the CAMPAIGN queue?')">
                        @csrf
                        <button type="submit" class="btn btn-danger w-100 py-3 fw-bold rounded-pill shadow-sm">
                            <i class="bi bi-trash3-fill me-2"></i> CLEAR CAMPAIGNS
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-3 col-md-6">
            <div class="card border-0 shadow-sm h-100 border-warning border-opacity-25" style="border-radius: 15px;">
                <div class="card-body d-flex flex-column justify-content-between">
                    <div>
                        <h5 class="fw-bold text-warning mb-2">Flush: Warmup</h5>
                        <p class="small text-muted">Permanently deletes all warmup jobs in the <code>warmup</code> queue.</p>
                    </div>
                    <form action="{{ route('admin.panic.Warmup') }}" method="POST" onsubmit="return confirm('WARNING: Clear the WARMUP queue?')">
                        @csrf
                        <button type="submit" class="btn btn-warning w-100 py-3 fw-bold text-white rounded-pill shadow-sm">
                            <i class="bi bi-fire me-2"></i> CLEAR WARMUP
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection