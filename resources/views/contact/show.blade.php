@extends('layouts.app-master')

@section('template_title')
    Profile: {{ $contact->name }}
@endsection

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <div>
            <h1 class="h3 mb-0 text-gray-800 fw-bold">
                <i class="bi bi-person-badge text-primary me-2"></i>Lead profile
            </h1>
            <p class="text-muted small mb-0">Profile and send history.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary btn-sm fw-bold shadow-sm px-3" href="{{ route('contacts.index') }}">
                <i class="bi bi-arrow-left me-1"></i> BACK
            </a>
            {{-- Manual sync --}}
            <button class="btn btn-info btn-sm fw-bold shadow-sm px-3 text-white" onclick="syncProfile({{ $contact->id }})" id="btnSync">
                <i class="bi bi-arrow-clockwise me-1" id="syncIcon"></i> SYNC
            </button>
            <a class="btn btn-primary btn-sm fw-bold shadow-sm px-3" href="{{ route('contacts.edit', $contact->id) }}">
                <i class="bi bi-pencil me-1"></i> EDIT
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-4 col-md-5">
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-body p-4 text-center">
                    {{-- Clickable photo to sync --}}
                    <div class="position-relative d-inline-block cursor-pointer" onclick="syncProfile({{ $contact->id }})" style="cursor: pointer;" title="Click to refresh photo">
                        <div id="avatarContainer">
                            @if($contact->profile_url)
                                <img src="{{ $contact->profile_url }}" 
                                     id="profileImage"
                                     alt="{{ $contact->name }}" 
                                     class="rounded-circle shadow border border-4 border-white" 
                                     style="width: 120px; height: 120px; object-fit: cover;">
                            @else
                                <div class="bg-light d-flex align-items-center justify-content-center rounded-circle shadow-sm border border-4 border-white mx-auto" 
                                     id="profilePlaceholder"
                                     style="width: 120px; height: 120px;">
                                    <i class="bi bi-person-fill fs-1 text-secondary"></i>
                                </div>
                            @endif
                        </div>
                        
                        {{-- Loading overlay --}}
                        <div id="syncLoader" class="position-absolute top-50 start-50 translate-middle d-none">
                            <div class="spinner-border text-primary" role="status"></div>
                        </div>

                        <span class="position-absolute bottom-0 end-0 border border-3 border-white badge rounded-pill p-2 {{ $contact->status === 'ativo' ? 'bg-success' : 'bg-danger' }}" 
                              id="statusBadge"
                              style="transform: translate(-10%, -10%);">
                        </span>
                    </div>

                    <h4 class="fw-bold mt-3 mb-0 text-dark">{{ $contact->name }}</h4>
                    <p class="text-muted small mb-3">Internal ID: #{{ $contact->id }}</p>
                    
                    <div class="d-flex justify-content-center gap-2">
                        <span class="badge bg-primary-soft text-primary px-3 py-2 rounded-pill shadow-sm">
                            <i class="bi bi-star-fill me-1"></i> SCORE: {{ $contact->score }}
                        </span>
                    </div>

                    <div class="list-group list-group-flush border-top mt-4 pt-3 text-start">
                        <div class="list-group-item border-0 px-0 py-2">
                            <label class="x-small fw-bold text-muted text-uppercase d-block mb-1">WhatsApp</label>
                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact->contact) }}" target="_blank" class="text-decoration-none fw-bold text-success">
                                <i class="bi bi-whatsapp me-2"></i>{{ $contact->contact }}
                            </a>
                        </div>
                        <div class="list-group-item border-0 px-0 py-2">
                            <label class="x-small fw-bold text-muted text-uppercase d-block mb-1">E-mail</label>
                            <span class="text-dark fw-medium small">{{ $contact->email ?? '---' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8 col-md-7">
            {{-- Recent jobs table --}}
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="m-0 font-weight-bold text-dark text-uppercase small">
                        <i class="bi bi-clock-history me-2 text-primary"></i>Recent sends
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light x-small text-muted text-uppercase fw-bold">
                                <tr>
                                    <th class="ps-4">Job ID</th>
                                    <th>Job status</th>
                                    <th>Status WhatsApp</th>
                                    <th class="text-end pe-4">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($contact->whatsappjobs()->orderBy('created_at', 'desc')->take(10)->get() as $job)
                                <tr>
                                    <td class="ps-4 text-muted">#{{ $job->id }}</td>
                                    <td>
                                        <span class="badge rounded-pill px-3 {{ $job->status == 'processado' ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger' }}">
                                            {{ strtoupper($job->status) }}
                                        </span>
                                    </td>
                                    <td class="small fw-bold">{{ $job->evolution_status ?? 'PENDING' }}</td>
                                    <td class="text-end pe-4">
                                        @if($job->status == 'erro')
                                            <form action="{{ route('whatsapp-jobs.retry', $job->id) }}" method="POST">
                                                @csrf
                                                <button type="submit" class="btn btn-xs btn-outline-danger fw-bold rounded-pill">RESEND</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="4" class="text-center py-4 text-muted">No history yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
async function syncProfile(id) {
    const btn = document.getElementById('btnSync');
    const icon = document.getElementById('syncIcon');
    const loader = document.getElementById('syncLoader');
    const img = document.getElementById('profileImage');
    const container = document.getElementById('avatarContainer');

    // Estado de carregamento
    btn.disabled = true;
    icon.classList.add('bi-spin'); // Add spin animation in CSS if desired
    loader.classList.remove('d-none');
    if(img) img.style.opacity = '0.3';

    try {
        const response = await fetch(`/contact/photo/${id}`);
        const photoUrl = await response.text();

        if (photoUrl && photoUrl !== '') {
            // Atualiza a imagem na tela sem refresh
            container.innerHTML = `<img src="${photoUrl}" id="profileImage" class="rounded-circle shadow border border-4 border-white" style="width: 120px; height: 120px; object-fit: cover;">`;
            
            // Atualiza o badge para ativo
            const badge = document.getElementById('statusBadge');
            badge.classList.remove('bg-danger');
            badge.classList.add('bg-success');
        } else {
            alert('The API did not return a photo for this number, or the contact does not exist on WhatsApp.');
        }
    } catch (error) {
        console.error('Sync error:', error);
        alert('Could not reach the server.');
    } finally {
        btn.disabled = false;
        icon.classList.remove('bi-spin');
        loader.classList.add('d-none');
    }
}
</script>

<style>
    .x-small { font-size: 0.68rem; letter-spacing: 0.5px; }
    .bg-success-soft { background-color: #e8f5e9; color: #2e7d32; }
    .bg-danger-soft { background-color: #ffebee; color: #c62828; }
    .bg-primary-soft { background-color: #eef2ff; }
    
    @keyframes spin {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }
    .bi-spin { 
        display: inline-block;
        animation: spin 1s linear infinite; 
    }
</style>
@endsection