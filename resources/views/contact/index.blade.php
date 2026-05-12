@extends('layouts.app-master')

@section('template_title', 'Contact management')

@section('content')
<div class="container-fluid py-4">
    {{-- Sticky bulk actions bar --}}
    <div id="bulkActionsBar" class="card border-0 shadow-lg bg-dark text-white position-fixed bottom-0 start-50 translate-middle-x mb-4 d-none" style="z-index: 1050; min-width: 500px; border-radius: 50px;">
        <div class="card-body d-flex align-items-center justify-content-between py-2 px-4">
            <div class="small">
                <span id="selectedCount" class="fw-bold text-warning">0</span> selected
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-success btn-sm fw-bold rounded-pill" onclick="submitBulk('ativo')">
                    <i class="bi bi-check-circle me-1"></i> ACTIVATE
                </button>
                <button type="button" class="btn btn-secondary btn-sm fw-bold rounded-pill" onclick="submitBulk('inativo')">
                    <i class="bi bi-slash-circle me-1"></i> DEACTIVATE
                </button>
                <button type="button" class="btn btn-danger btn-sm fw-bold rounded-pill" onclick="submitBulk('delete')">
                    <i class="bi bi-trash me-1"></i> REMOVE
                </button>
                <button type="button" class="btn btn-link btn-sm text-white text-decoration-none" onclick="toggleSelectAll(false)">
                    Cancel
                </button>
            </div>
        </div>
    </div>

    {{-- Hidden form for bulk actions --}}
    <form id="bulkActionForm" method="POST" action="" class="d-none">
        @csrf
        <input type="hidden" name="ids" id="bulkIdsInput">
        <input type="hidden" name="status_value" id="bulkStatusInput">
    </form>

    <div class="row">
        <div class="col-sm-12">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
                <h1 class="h3 mb-0 text-gray-800 fw-bold">
                    <i class="bi bi-people-fill text-primary me-2"></i>Contacts
                </h1>
                <div class="d-flex gap-2">
                    <form method="GET" action="{{ route('contacts.index') }}" class="d-flex gap-1 shadow-sm rounded bg-white">
                        <input type="text" name="search" class="form-control form-control-sm border-0 px-3 shadow-none"
                            placeholder="Name, WhatsApp or email..." value="{{ request('search') }}" style="min-width: 250px;">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i>
                        </button>
                    </form>
                    <a href="{{ route('contacts.create') }}" class="btn btn-success btn-sm fw-bold px-3 shadow-sm">
                        <i class="bi bi-plus-lg"></i> NEW
                    </a>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center border-bottom">
                    <h6 class="m-0 font-weight-bold text-primary">Leads ({{ $contacts->total() }})</h6>
                </div>

                @if ($message = Session::get('success'))
                <div class="alert alert-success border-0 rounded-0 m-0">
                    <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
                </div>
                @endif

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="contactsTable">
                            <thead class="table-light x-small text-uppercase fw-bold text-muted">
                                <tr>
                                    <th class="ps-4" style="width: 40px;">
                                        <input type="checkbox" class="form-check-input shadow-none" id="selectAll" onclick="toggleSelectAll(this.checked)">
                                    </th>
                                    <th style="width: 60px;">Photo</th>
                                    <th>Name / info</th>
                                    <th>WhatsApp</th>
                                    <th class="text-center">Score</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($contacts as $contact)
                                <tr id="row-{{ $contact->id }}">
                                    <td class="ps-4">
                                        <input type="checkbox" class="form-check-input contact-checkbox shadow-none" value="{{ $contact->id }}" onclick="updateBulkBar()">
                                    </td>
                                    <td>
                                        {{-- Avatar cell + sync --}}
                                        <div class="position-relative d-inline-block" style="cursor: pointer;" onclick="syncProfileRow({{ $contact->id }})" id="avatar-container-{{ $contact->id }}">
                                            @if($contact->profile_url)
                                                <img src="{{ $contact->profile_url }}" id="img-{{ $contact->id }}" class="rounded-circle shadow-sm border" style="width: 40px; height: 40px; object-fit: cover;">
                                            @else
                                                <div class="bg-light rounded-circle d-flex align-items-center justify-content-center border" style="width: 40px; height: 40px;" id="placeholder-{{ $contact->id }}">
                                                    <i class="bi bi-person text-secondary" style="font-size: 1.2rem;"></i>
                                                </div>
                                            @endif
                                            {{-- Subtle row loader --}}
                                            <div id="loader-{{ $contact->id }}" class="position-absolute top-50 start-50 translate-middle d-none">
                                                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark d-block">{{ $contact->name }}</div>
                                        @if($contact->lid)
                                        <span class="badge bg-light text-primary border fw-normal" style="font-size: 0.65rem;">
                                            <i class="bi bi-tag-fill me-1"></i>{{ $contact->lid }}
                                        </span>
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact->contact) }}" target="_blank" class="text-decoration-none small fw-bold text-success">
                                                <i class="bi bi-whatsapp me-1"></i>{{ $contact->contact }}
                                            </a>
                                            <span class="text-muted x-small">{{ $contact->email ?? '---' }}</span>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <span class="fw-bold {{ $contact->score > 50 ? 'text-success' : 'text-muted' }}">
                                            {{ $contact->score }}
                                        </span>
                                    </td>
                                    <td class="text-center" id="status-cell-{{ $contact->id }}">
                                        @if($contact->status === 'ativo')
                                        <span class="badge bg-success-soft text-success rounded-pill px-3">Active</span>
                                        @elseif($contact->status === 'no-whatsapp')
                                        <span class="badge bg-danger-soft text-danger rounded-pill px-3">No-WA</span>
                                        @else
                                        <span class="badge bg-secondary-soft text-secondary rounded-pill px-3">{{ $contact->status }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group btn-group-sm shadow-sm border rounded bg-white">
                                            <a class="btn btn-white border-end" href="{{ route('contacts.show',$contact->id) }}" title="View"><i class="bi bi-eye"></i></a>
                                            <a class="btn btn-white border-end text-primary" href="{{ route('contacts.edit',$contact->id) }}" title="Edit"><i class="bi bi-pencil"></i></a>
                                            <form action="{{ route('contacts.destroy',$contact->id) }}" method="POST" style="display:inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-white text-danger" onclick="return confirm('Delete contact?')"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @empty
                                <tr><td colspan="7" class="text-center py-5 text-muted">No contacts found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="py-4 d-flex justify-content-center border-top bg-light">
                    {{ $contacts->appends(request()->query())->links('pagination::bootstrap-4') }}
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Per-row photo sync
    async function syncProfileRow(id) {
        const loader = document.getElementById(`loader-${id}`);
        const container = document.getElementById(`avatar-container-${id}`);
        const statusCell = document.getElementById(`status-cell-${id}`);
        
        loader.classList.remove('d-none');
        container.style.opacity = '0.5';

        try {
            const response = await fetch(`/contact/photo/${id}`);
            const photoUrl = await response.text();

            if (photoUrl && photoUrl !== '') {
                container.innerHTML = `<img src="${photoUrl}" class="rounded-circle shadow-sm border" style="width: 40px; height: 40px; object-fit: cover;">`;
                statusCell.innerHTML = `<span class="badge bg-success-soft text-success rounded-pill px-3">Active</span>`;
            } else {
                statusCell.innerHTML = `<span class="badge bg-danger-soft text-danger rounded-pill px-3">No-WA</span>`;
            }
        } catch (error) {
            console.error('Error:', error);
        } finally {
            loader.classList.add('d-none');
            container.style.opacity = '1';
        }
    }

    function toggleSelectAll(checked) {
        document.querySelectorAll('.contact-checkbox').forEach(cb => cb.checked = checked);
        document.getElementById('selectAll').checked = checked;
        updateBulkBar();
    }

    function updateBulkBar() {
        const selected = document.querySelectorAll('.contact-checkbox:checked');
        const bar = document.getElementById('bulkActionsBar');
        const countSpan = document.getElementById('selectedCount');

        if (selected.length > 0) {
            bar.classList.remove('d-none');
            countSpan.innerText = selected.length;
        } else {
            bar.classList.add('d-none');
        }
    }

    function submitBulk(action) {
        const ids = Array.from(document.querySelectorAll('.contact-checkbox:checked')).map(cb => cb.value);
        if (ids.length === 0) return;

        let confirmMsg = (action === 'delete') 
            ? `Remove ${ids.length} contacts?` 
            : `Change status for ${ids.length} contacts to '${action}'?`;

        if (!confirm(confirmMsg)) return;

        document.getElementById('bulkActionForm').action = (action === 'delete') 
            ? "{{ route('contacts.bulk-delete') }}" 
            : "{{ route('contacts.bulk-status') }}";
            
        document.getElementById('bulkIdsInput').value = JSON.stringify(ids);
        document.getElementById('bulkStatusInput').value = action;
        document.getElementById('bulkActionForm').submit();
    }
</script>

<style>
    .x-small { font-size: 0.7rem; }
    .bg-success-soft { background-color: #e8f5e9; color: #2e7d32; }
    .bg-danger-soft { background-color: #ffebee; color: #c62828; }
    .bg-secondary-soft { background-color: #f5f5f5; color: #757575; }
    .btn-white { background: white; border: none; }
    .btn-white:hover { background: #f8f9fa; }
    .spinner-border-sm { width: 1rem; height: 1rem; border-width: 0.15em; }
</style>
@endsection