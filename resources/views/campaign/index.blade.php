@extends('layouts.app-master')

@section('template_title', 'Campaign management')

@section('content')
<div class="container-fluid py-4">
    <div class="row">
        <div class="col-sm-12">
            {{-- Header --}}
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
                <div>
                    <h2 class="fw-bold text-dark mb-1">
                        <i class="bi bi-megaphone-fill text-primary me-2"></i>Broadcast campaigns
                    </h2>
                    <p class="text-muted mb-0 small">Monitor performance and health of your bulk sends.</p>
                </div>
                <a href="{{ route('campaigns.create') }}" class="btn btn-primary px-4 shadow-sm fw-bold">
                    <i class="bi bi-plus-lg me-2"></i>Create campaign
                </a>
            </div>

            @if ($message = Session::get('success'))
            <div class="alert alert-success border-0 shadow-sm mb-4">
                <i class="bi bi-check-circle-fill me-2"></i> {{ $message }}
            </div>
            @endif

            <div class="card border-0 shadow-sm" style="border-radius: 15px; overflow: hidden;">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light text-muted small text-uppercase">
                                <tr>
                                    <th class="ps-4 py-3" style="width: 80px;">ID</th>
                                    <th class="py-3">Campaign / Owner</th>
                                    <th class="py-3">Performance (success rate)</th>
                                    <th class="py-3 text-center">Status</th>
                                    <th class="py-3 text-center pe-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($campaigns as $campaign)
                                <tr>
                                    <td class="ps-4">
                                        <span class="text-muted font-monospace small">#{{ $campaign->id }}</span>
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark" style="font-size: 1rem;">{{ $campaign->name }}</div>
                                        <div class="text-muted small">
                                            <i class="bi bi-person me-1"></i>{{ $campaign->user->name ?? 'System' }}
                                        </div>
                                    </td>
                                    <td style="min-width: 250px;">
                                        @php $rate = $campaign->getSuccessRate(); @endphp
                                        <div class="d-flex justify-content-between mb-2 small">
                                            <span class="text-muted">Delivery</span>
                                            <span class="fw-bold {{ $rate < 50 ? 'text-danger' : 'text-success' }}">{{ $rate }}%</span>
                                        </div>
                                        <div class="progress shadow-sm" style="height: 8px; border-radius: 10px;">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated {{ $rate < 50 ? 'bg-danger' : 'bg-success' }}"
                                                role="progressbar"
                                                style="width: {{ $rate }}%"></div>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        {{-- Dynamic status logic placeholder --}}
                                        <span class="badge rounded-pill bg-soft-success text-success border border-success px-3 py-2">
                                            <i class="bi bi-play-fill me-1"></i> ACTIVE
                                        </span>
                                    </td>
                                    <td class="text-center pe-4">
                                        <div class="btn-group shadow-sm border rounded">
                                            {{-- Detailed report route --}}
                                            <a class="btn btn-sm btn-primary px-3" href="{{ route('campaigns.report', $campaign->id) }}" title="Full report">
                                                <i class="bi bi-bar-chart-line-fill me-1"></i> Report
                                            </a>

                                            <a class="btn btn-sm btn-white text-dark border-start" href="{{ route('campaigns.show', $campaign->id) }}" title="View">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a class="btn btn-sm btn-white text-dark border-start" href="{{ route('campaigns.edit', $campaign->id) }}" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </a>

                                            <form action="{{ route('campaigns.destroy', $campaign->id) }}" method="POST" class="d-inline" onsubmit="return confirm('WARNING: Deleting this campaign will remove all send logs. Continue?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-white text-danger border-start" title="Delete">
                                                    <i class="bi bi-trash3"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 py-4">
                    <div class="d-flex justify-content-center">
                        {{ $campaigns->appends(request()->query())->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Modern styles */
    .bg-soft-success {
        background-color: #e8f5e9;
        color: #2e7d32;
    }

    .table-hover tbody tr:hover {
        background-color: #fcfdfe;
        transition: 0.2s;
    }

    .btn-white {
        background: #fff;
        color: #64748b;
    }

    .btn-white:hover {
        background: #f8fafc;
        color: #1e293b;
    }

    .card {
        border: 1px solid rgba(0, 0, 0, .05) !important;
    }

    .progress {
        background-color: #f1f5f9;
    }
</style>
@endsection