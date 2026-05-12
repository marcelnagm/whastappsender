@extends('layouts.app-master')

@section('content')
    <div class="py-4">
        @include('layouts.partials.messages')

        @auth
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2"><i class="bi bi-speedometer2"></i> Control Panel</h1>
            <div class="btn-toolbar mb-2 mb-md-0">
                <button type="button" class="btn btn-sm btn-outline-primary me-2">
                    <i class="bi bi-download"></i> Export Leads
                </button>
                <button type="button" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle"></i> New Campaign
                </button>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-primary bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-people-fill fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Total mined</h6>
                                <h3 class="fw-bold mb-0">{{$contact}}</h3> </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-success bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-whatsapp fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Active instances</h6>
                                <h3 class="fw-bold mb-0">{{$instances}}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-info bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-send-check fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Read (today)</h6>
                                <h3 class="fw-bold mb-0">{{$read}}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card border-0 shadow-sm rounded-3">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0 bg-danger bg-gradient text-white p-3 rounded-3">
                                <i class="bi bi-exclamation-triangle fs-3"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h6 class="text-muted mb-0">Critical failures</h6>
                                <h3 class="fw-bold mb-0">{{$error}}</h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">

            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-header bg-white py-3 text-center">
                        <h5 class="card-title mb-0 fw-bold">Support & activation</h5>
                    </div>
                    <div class="card-body text-center py-4">
                        <i class="bi bi-headset fs-1 text-primary mb-3"></i>
                        <p>Questions or license upgrade?</p>
                        <a href="https://wa.me/{{env('WHATSAPP_CONTACT_TEST')}}" target="_blank" class="btn btn-success w-100">
                            <i class="bi bi-whatsapp"></i> Contact support
                        </a>
                    </div>
                </div>
            </div>
        </div>
        @endauth

        @guest
        <div class="text-center py-5">
            <h1 class="display-4 fw-bold">Mining <span class="text-primary">System</span></h1>
            <p class="lead mb-4">Sign in to the platform to view restricted mining data.</p>
            <div class="d-grid gap-2 d-sm-flex justify-content-sm-center">
                <a href="{{ route('login.perform') }}" class="btn btn-primary btn-lg px-4 gap-3">Sign in</a>
                <a href="mailto:marcel.nagm@gmail.com" class="btn btn-outline-secondary btn-lg px-4">Request access</a>
            </div>
        </div>
        @endguest
    </div>
@endsection
