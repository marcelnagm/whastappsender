@extends('layouts.app-master')

@section('admin_title')
{{ config('settings.url_route') . " " . __('Management')}}
@endsection

@section('content')
<div class="header bg-gradient-primary pb-8 pt-5 pt-md-8">
</div>

<div class="container-fluid mt--7">
    <div class="row justify-content-center">
        <div class="col-lg-11 col-xl-10">
            <div class="card shadow border-0 overflow-hidden">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-md-7 p-4 p-lg-5 bg-white">
                            <div class="d-flex align-items-center mb-4">
                                <div class="icon icon-shape bg-success text-white rounded-circle shadow me-3">
                                    <i class="fab fa-whatsapp"></i>
                                </div>
                                <h2 class="mb-0 fw-bold">Connect WhatsApp</h2>
                            </div>

                            <h4 class="text-muted mb-4">Follow these steps to pair your device:</h4>

                            <ul class="list-unstyled mb-5">
                                <li class="mb-4 d-flex align-items-start">
                                    <span
                                        class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center"
                                        style="width:28px; height:28px;">1</span>
                                    <div class="text-dark">Open <strong>WhatsApp</strong> on your phone.</div>
                                </li>
                                <li class="mb-4 d-flex align-items-start">
                                    <span
                                        class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center"
                                        style="width:28px; height:28px;">2</span>
                                    <div>
                                        Tap <strong>More options</strong> ( <i class="fas fa-ellipsis-v small"></i>
                                        ) or
                                        <strong>Settings</strong> ( <i class="fas fa-cog small"></i> ).
                                    </div>
                                </li>
                                <li class="mb-4 d-flex align-items-start">
                                    <span
                                        class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center"
                                        style="width:28px; height:28px;">3</span>
                                    <div>Select <strong>Linked devices</strong> and tap <strong>Link a device</strong>.</div>
                                </li>
                                <li class="d-flex align-items-start">
                                    <span
                                        class="badge badge-primary rounded-circle me-3 d-flex align-items-center justify-content-center"
                                        style="width:28px; height:28px;">4</span>
                                    <div>Point your camera at this screen to scan the <strong>QR code</strong>.</div>
                                </li>
                            </ul>

                            <div class="alert alert-secondary border-0 shadow-none rounded-3">
                                <div class="d-flex align-items-center text-dark small">
                                    <i class="fas fa-info-circle text-info me-2 fs-4"></i>
                                    <span><strong>Important:</strong> Keep the phone online so mining and sending jobs can run in real time.</span>
                                </div>
                            </div>
                        </div>

                        <div
                            class="col-md-5 bg-light d-flex align-items-center justify-content-center border-start py-5">
                            <div id="qr_section" class="text-center px-4">
                                <div class="mb-4 bg-white shadow-sm d-inline-block p-3 rounded-lg border position-relative"
                                    style="border-radius: 15px;">
                                    <div id="qr" class="d-flex align-items-center justify-content-center"
                                        style="min-height: 260px; min-width: 260px;">
                                        @if(is_array($res) && isset($res['base64']))
                                        <img src="{{ $res['base64'] }}" class="img-fluid" alt="QR Code"
                                            id="current_qr_img">
                                        @else
                                        <div class="text-center text-muted py-5" id="qr_placeholder">
                                            <i class="fas fa-sync fa-spin fa-3x mb-3 text-lighter"></i>
                                            <p class="small fw-bold">Starting session...</p>
                                        </div>
                                        @endif
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="button" onclick="getQR();" class="btn btn-primary btn-lg shadow-sm">
                                        <i class="fas fa-sync-alt me-2"></i> Refresh QR code
                                    </button>
                                </div>
                                <p class="text-muted mt-3 x-small"><i class="fas fa-lock me-1"></i> End-to-end encrypted connection.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .x-small {
        font-size: 0.75rem;
    }

    .text-lighter {
        color: #e9ecef;
    }

    #qr img {
        max-width: 260px;
        height: auto;
        display: block;
    }

    .badge-primary {
        background-color: #5e72e4 !important;
    }

    @media (max-width: 768px) {
        .border-start {
            border-left: none !important;
            border-top: 1px solid #dee2e6 !important;
        }
    }
</style>
@endsection
@section('js')
<script>
    const session = "{{ $instance }}";
    let qrcod_lido = false;

    $(document).ready(function() {
        getQR();
        if (typeof checkSessionStatus === "function") {
            checkSessionStatus();
        }
    });

    function getQR() {
        const qrContainer = $("#qr");
        qrContainer.html('<div class="text-center text-muted"><i class="fas fa-sync fa-spin fa-2x mb-2"></i><p class="small">Generating QR code...</p></div>');

        $.ajax({
            url: "{{ route('whatsapp.qr', ['id' => $instance]) }}",
            type: 'GET',
            dataType: 'json',
            success: function(data) {
                // Prefer base64 from root or nested under qrcode
                // The raw `code` (2@...) is only used if base64 is missing and it includes an image header
                let qrData = null;

                if (data.base64) {
                    qrData = data.base64;
                } else if (data.qrcode && data.qrcode.base64) {
                    qrData = data.qrcode.base64;
                } else if (data.code && data.code.includes('data:image')) {
                    qrData = data.code;
                }

                if (qrData) {
                    const img = new Image();
                    img.src = qrData.includes('data:image') ? qrData : `data:image/png;base64,${qrData}`;
                    img.className = "img-fluid animate__animated animate__fadeIn";
                    img.style.maxWidth = "260px";

                    qrContainer.html(img);
                } else {
                    console.error("Response without base64:", data);
                    qrContainer.html('<p class="text-danger small">The API did not return the image (base64). Check the console.</p>');
                }
            },
            error: function(err) {
                console.error("Laravel proxy error:", err);
                qrContainer.html('<p class="text-danger small">Error communicating with the server.</p>');
            }
        });
    }
</script>
@endsection