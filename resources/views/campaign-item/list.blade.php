<div class="container-fluid py-4">
    @php
        $generationNotifications = auth()->user()->notifications
            ->filter(function ($notification) {
                return ($notification->data['context'] ?? null) === 'campaign_item_generate'
                    && !empty($notification->data['campaign_item_id']);
            })
            ->sortByDesc('created_at')
            ->groupBy(function ($notification) {
                return (int) ($notification->data['campaign_item_id'] ?? 0);
            });
    @endphp

    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-1">
                    <li class="breadcrumb-item small text-uppercase fw-bold"><a href="#" class="text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item small text-uppercase fw-bold active">Campaigns</li>
                </ol>
            </nav>
            <h1 class="h3 mb-0 text-gray-800 fw-bold">
                <span class="bg-primary bg-opacity-10 p-2 rounded-3 me-2">
                    <i class="bi bi-layers-fill text-primary"></i>
                </span>
                Campaign items
            </h1>
        </div>
        <a href="{{ route('campaign-items.create') }}" class="btn btn-primary px-4 py-2 fw-bold shadow-sm rounded-pill">
            <i class="bi bi-plus-lg me-2"></i> NOVO ITEM
        </a>
    </div>

    @include('layouts.partials.messages')

    <div class="card border-0 shadow-sm" style="border-radius: 15px; position: relative;">
        <div class="card-body p-0">
            <div class="table-responsive" style="overflow: visible !important;">
                <table class="table table-hover align-middle mb-0" style="background: white;">
                    <thead class="bg-light border-bottom">
                        <tr>
                            <th class="ps-4 py-3 text-muted small fw-bold text-uppercase" style="letter-spacing: 0.5px;">Name</th>
                            <th class="py-3 text-muted small fw-bold text-uppercase">Content</th>
                            <th class="py-3 text-center text-muted small fw-bold text-uppercase">Media</th>
                            <th class="py-3 text-muted small fw-bold text-uppercase">Performance (ACK)</th>
                            <th class="py-3 text-end pe-4 text-muted small fw-bold text-uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($campaignItems as $campaignItem)
                        @php $rate = $campaignItem->getDeliveryRate(); @endphp
                        @php
                            $itemStatusNotification = optional($generationNotifications->get((int) $campaignItem->id))->first();
                            $itemStatus = $itemStatusNotification->data['status'] ?? null;
                        @endphp
                        <tr class="transition-all table-row-custom">
                            <td class="ps-4 py-3">
                                <div class="d-flex align-items-center">
                                    <div class="bg-light text-primary fw-bold rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px; font-size: 0.8rem;">
                                        #{{ $campaignItem->id }}
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark mb-0">{{ $campaignItem->name }}</div>
                                        <div class="badge bg-soft-primary text-primary x-small fw-normal">
                                            <i class="bi bi-tag-fill me-1"></i>{{ $campaignItem->campaign->name ?? 'N/A' }}
                                        </div>
                                        @if($itemStatus === 'started')
                                        <div class="mt-1">
                                            <span class="badge bg-warning-subtle text-warning-emphasis x-small fw-normal">
                                                <i class="bi bi-hourglass-split me-1"></i> Generating...
                                            </span>
                                        </div>
                                        @elseif($itemStatus === 'completed')
                                        <div class="mt-1">
                                            <span class="badge bg-info-subtle text-info-emphasis x-small fw-normal">
                                                <i class="bi bi-check2-circle me-1"></i> Generation complete
                                            </span>
                                        </div>
                                        @elseif($itemStatus === 'error')
                                        <div class="mt-1">
                                            <span class="badge bg-danger-subtle text-danger-emphasis x-small fw-normal">
                                                <i class="bi bi-x-circle me-1"></i> Generation failed
                                            </span>
                                        </div>
                                        @endif
                                        @if($campaignItem->welcome_enabled)
                                        <div class="mt-1">
                                            <span class="badge bg-success-subtle text-success-emphasis x-small fw-normal">
                                                <i class="bi bi-chat-heart-fill me-1"></i> Welcome
                                            </span>
                                        </div>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="py-3">
                                <div class="text-muted small" style="max-width: 250px; line-height: 1.4;">
                                    {{ Str::limit($campaignItem->text, 65) }}
                                </div>
                            </td>

                            <td class="text-center py-3">
                                @if($campaignItem->image)
                                <div class="media-tooltip-container">
                                    <div class="avatar-preview shadow-sm border">
                                        <img src="{{ $campaignItem->image }}" alt="Media" style="width: 32px; height: 32px; object-fit: cover; border-radius: 6px;">
                                    </div>
                                    <div class="media-tooltip-content">
                                        <img src="{{ $campaignItem->image }}" alt="Preview">
                                        <div class="p-2 text-center small fw-bold text-dark border-top bg-light">Media preview</div>
                                    </div>
                                </div>
                                @else
                                <span class="badge bg-light text-muted fw-normal px-2 py-1"><i class="bi bi-chat-left-text me-1"></i> Text</span>
                                @endif
                            </td>

                            <td class="py-3" style="min-width: 200px;">
                                <div class="d-flex align-items-center">
                                    <div class="progress flex-grow-1 shadow-none" style="height: 6px; border-radius: 10px; background-color: #e9ecef;">
                                        <div class="progress-bar rounded-pill {{ $rate >= 80 ? 'bg-success' : ($rate >= 50 ? 'bg-warning' : 'bg-danger') }}"
                                            style="width: {{ $rate }}%"></div>
                                    </div>
                                    <span class="ms-3 fw-bold small {{ $rate >= 80 ? 'text-success' : ($rate >= 50 ? 'text-warning' : 'text-danger') }}">
                                        {{ $rate }}%
                                    </span>
                                </div>
                            </td>

                            <td class="text-end pe-4 py-3">
                                <div class="d-flex justify-content-end gap-2">
                                    <div class="btn-group shadow-none">
                                        <button type="button" class="btn btn-sm btn-primary fw-bold px-3 dropdown-toggle shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-lightning-charge-fill me-1"></i> EXECUTAR
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="border-radius: 12px; z-index: 9999;">
                                            <li><h6 class="dropdown-header text-uppercase x-small fw-bold">Processing</h6></li>
                                            <li><a class="dropdown-item rounded-2 py-2" href="{{ route('campaign-items.generateAll',$campaignItem->id) }}"><i class="bi bi-people-fill me-2 text-muted"></i> Generate for all</a></li>
                                            @if(Auth::user()->role === 'admin')
                                            <li><a class="dropdown-item rounded-2 py-2" href="{{ route('campaign-items.generate',$campaignItem->id) }}"><i class="bi bi-bug me-2 text-muted"></i> Generate test</a></li>
                                            @endif
                                            <li><hr class="dropdown-divider"></li>
                                            <li><a class="dropdown-item rounded-2 py-2 text-success fw-bold" href="{{ route('campaign-items.send',$campaignItem->id) }}"><i class="bi bi-send-check-fill me-2"></i> Start sending</a></li>
                                        </ul>
                                    </div>

                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-light border dropdown-toggle no-caret shadow-none" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                            <i class="bi bi-three-dots-vertical"></i>
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow border-0 p-2" style="border-radius: 12px; z-index: 9999;">
                                            <li><a class="dropdown-item rounded-2" href="{{ route('campaign-items.show',$campaignItem->id) }}"><i class="bi bi-eye me-2"></i> View</a></li>
                                            <li><a class="dropdown-item rounded-2" href="{{ route('campaign-items.edit',$campaignItem->id) }}"><i class="bi bi-pencil me-2"></i> Edit</a></li>
                                            <li><a class="dropdown-item rounded-2" href="{{ route('whatsapp-jobs.index',$campaignItem->id) }}"><i class="bi bi-list-columns-reverse me-2"></i> View logs</a></li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <form action="{{ route('campaign-items.destroy',$campaignItem->id) }}" method="POST">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="dropdown-item rounded-2 text-danger" onclick="return confirm('Delete this item?')">
                                                        <i class="bi bi-trash me-2"></i> Delete
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="py-4 d-flex justify-content-center">
        {{ $campaignItems->appends(request()->query())->links('pagination::bootstrap-4') }}
    </div>
</div>

<style>
    .x-small { font-size: 0.68rem; letter-spacing: 0.2px; }
    .bg-soft-primary { background-color: #e7f1ff; }
    .transition-all { transition: all 0.2s ease-in-out; }
    .no-caret::after { display: none; }

    /* Raise row when dropdown is open */
    .table-row-custom:focus-within {
        position: relative;
        z-index: 1050 !important;
    }

    .table-hover tbody tr:hover {
        background-color: #fcfdfe;
        transform: scale(1.002);
        box-shadow: inset 4px 0 0 #0d6efd;
    }

    .media-tooltip-container { position: relative; display: inline-block; }
    .avatar-preview { transition: transform 0.2s; }
    .avatar-preview:hover { transform: scale(1.1); }

    .media-tooltip-content {
        visibility: hidden;
        position: absolute;
        z-index: 1000;
        bottom: 130%;
        left: 50%;
        transform: translateX(-50%);
        width: 220px;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.2);
        opacity: 0;
        transition: all 0.3s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        border: 1px solid #eee;
        overflow: hidden;
    }

    .media-tooltip-content img { width: 100%; height: 160px; object-fit: cover; }
    .media-tooltip-container:hover .media-tooltip-content { visibility: visible; opacity: 1; bottom: 110%; }

    .table-responsive::-webkit-scrollbar { height: 6px; }
    .table-responsive::-webkit-scrollbar-thumb { background: #dee2e6; border-radius: 10px; }

    /* Prevent dropdown clipping from parent overflow */
    .dropdown-menu {
        margin-top: 0.5rem !important;
    }
</style>