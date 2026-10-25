@php
    $__ent = app(\App\Services\Billing\Entitlements::class);
    $__u = auth()->user();
    $__plan = $__ent->plan($__u);
    $__status = $__ent->status($__u);
    $__sub = $__ent->subscription($__u);
    $__usage = $__ent->usageSummary($__u);
    $__labels = [
        'instances' => __('Connected numbers'), 'contacts' => __('Contacts'),
        'messages_per_month' => __('Messages this month'), 'ai_replies_per_month' => __('AI replies this month'),
    ];
    $__badge = ['free' => 'secondary', 'pending' => 'warning', 'trialing' => 'info', 'active' => 'success', 'past_due' => 'warning', 'suspended' => 'danger'];
    $__text = ['free' => __('Free'), 'pending' => __('Waiting for payment'), 'trialing' => __('Free trial'), 'active' => __('Active'), 'past_due' => __('Payment overdue'), 'suspended' => __('Blocked')];
@endphp

<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold"><i class="bi bi-credit-card me-2 text-primary"></i>{{ __('Your plan') }}: {{ $__plan->name }}</h5>
        <span class="badge bg-{{ $__badge[$__status] ?? 'secondary' }}">{{ $__text[$__status] ?? $__status }}</span>
    </div>
    <div class="card-body">
        @if(!$__ent->enabled())
            <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>{{ __('Billing is not enabled yet: every feature is available without limits.') }}</p>
        @elseif($__ent->isUnlimited($__u))
            <p class="text-muted small mb-0"><i class="bi bi-shield-check me-1"></i>{{ __('Administrator accounts have no limits.') }}</p>
        @else
            <div class="row g-3">
                @foreach($__usage as $__key => $__row)
                    @php
                        $__pct = $__row['limit'] ? min(100, round($__row['used'] / max(1, $__row['limit']) * 100)) : 0;
                        $__bar = $__pct >= 100 ? 'danger' : ($__pct >= 80 ? 'warning' : 'success');
                    @endphp
                    <div class="col-sm-6">
                        <div class="d-flex justify-content-between small">
                            <span>{{ $__labels[$__key] }}</span>
                            <span class="text-muted">{{ number_format($__row['used'], 0, ',', '.') }} / {{ $__row['limit'] === null ? '∞' : number_format($__row['limit'], 0, ',', '.') }}</span>
                        </div>
                        <div class="progress" style="height: 6px;"><div class="progress-bar bg-{{ $__bar }}" style="width: {{ $__row['limit'] === null ? 0 : $__pct }}%"></div></div>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 small text-muted">
                @if($__sub && $__sub->status === 'trialing')
                    {{ __('Trial ends') }}: {{ optional($__sub->trial_ends_at)->format('d/m/Y') }}
                @elseif($__sub && $__sub->current_period_end)
                    {{ $__sub->cancel_at_period_end ? __('Access until') : __('Next renewal') }}: {{ $__sub->current_period_end->format('d/m/Y') }}
                @endif
            </div>
        @endif
    </div>
    <div class="card-footer bg-white border-0 pb-3">
        <a href="{{ route('billing.index') }}" class="btn btn-sm btn-primary">{{ __('See plans') }}</a>
    </div>
</div>
