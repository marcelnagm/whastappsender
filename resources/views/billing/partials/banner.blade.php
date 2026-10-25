@php
    $__ent = app(\App\Services\Billing\Entitlements::class);
    $__user = auth()->user();
    $__sub = ($__ent->enabled() && !$__ent->isUnlimited($__user)) ? $__ent->subscription($__user) : null;
@endphp

@if(session('plan_error'))
    <div class="alert alert-warning d-flex justify-content-between align-items-center" role="alert">
        <span><i class="bi bi-lock me-2"></i>{{ session('plan_error') }}</span>
    </div>
@endif

@if($__sub && !request()->is('billing*'))
    @if($__sub->status === 'suspended')
        <div class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
            <span><i class="bi bi-exclamation-octagon me-2"></i>{{ __('Your account is blocked because of an overdue payment. Pay the open invoice to restore access.') }}</span>
            <a href="{{ route('billing.index') }}" class="btn btn-sm btn-danger">{{ __('Pay now') }}</a>
        </div>
    @elseif($__sub->status === 'past_due')
        <div class="alert alert-warning d-flex justify-content-between align-items-center" role="alert">
            <span><i class="bi bi-exclamation-triangle me-2"></i>{{ __('Payment overdue. Full access continues until :date.', ['date' => optional($__sub->grace_ends_at)->format('d/m/Y')]) }}</span>
            <a href="{{ route('billing.index') }}" class="btn btn-sm btn-warning">{{ __('Pay now') }}</a>
        </div>
    @elseif($__sub->status === 'trialing' && $__sub->trial_ends_at && $__sub->trial_ends_at->diffInDays(now()) <= 3)
        <div class="alert alert-info d-flex justify-content-between align-items-center" role="alert">
            <span><i class="bi bi-hourglass-split me-2"></i>{{ __('Your free trial ends on :date.', ['date' => $__sub->trial_ends_at->format('d/m/Y')]) }}</span>
            <a href="{{ route('billing.index') }}" class="btn btn-sm btn-info">{{ __('See plans') }}</a>
        </div>
    @endif
@endif
