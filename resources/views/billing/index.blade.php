@extends('layouts.app-master')

@section('content')
@php
    $money = fn ($cents, $cur = 'BRL') => ($cur === 'BRL' ? 'R$ ' : $cur . ' ') . number_format($cents / 100, 2, ',', '.');
    $featureLabels = \App\Models\Plan::featureLabels();
    $limitLabels = \App\Models\Plan::limitLabels();
    $usageLabels = ['instances' => __('Connected numbers'), 'contacts' => __('Contacts'), 'messages_per_month' => __('Messages this month'), 'ai_replies_per_month' => __('AI replies this month')];
    $statusBadge = ['free' => 'secondary', 'pending' => 'warning', 'trialing' => 'info', 'active' => 'success', 'past_due' => 'warning', 'suspended' => 'danger'];
    $statusText = ['free' => __('Free'), 'pending' => __('Waiting for payment'), 'trialing' => __('Free trial'), 'active' => __('Active'), 'past_due' => __('Payment overdue'), 'suspended' => __('Blocked')];
@endphp

<div class="container py-4">
    @include('layouts.partials.messages')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-dark mb-1"><i class="bi bi-credit-card text-primary me-2"></i>{{ __('Plan and billing') }}</h2>
            <p class="text-muted small mb-0">{{ __('Your subscription, usage and invoices.') }}</p>
        </div>
    </div>

    @if(!$enabled)
        <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>{{ __('Billing is not enabled yet: every feature is available without limits.') }}</div>
    @elseif($unlimited)
        <div class="alert alert-info"><i class="bi bi-shield-check me-2"></i>{{ __('Administrator accounts have no limits.') }}</div>
    @endif

    {{-- Current subscription --}}
    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <div class="text-muted small">{{ __('Current plan') }}</div>
                            <h3 class="fw-bold mb-0">{{ $currentPlan->name }}</h3>
                        </div>
                        <span class="badge bg-{{ $statusBadge[$status] ?? 'secondary' }}">{{ $statusText[$status] ?? $status }}</span>
                    </div>

                    @if($subscription)
                        <dl class="row small mb-3">
                            <dt class="col-6 text-muted fw-normal">{{ __('Billing cycle') }}</dt>
                            <dd class="col-6 text-end">{{ $subscription->cycle === 'yearly' ? __('Yearly') : __('Monthly') }}</dd>
                            @if($subscription->status === 'trialing')
                                <dt class="col-6 text-muted fw-normal">{{ __('Trial ends') }}</dt>
                                <dd class="col-6 text-end">{{ optional($subscription->trial_ends_at)->format('d/m/Y') }}</dd>
                            @elseif($subscription->current_period_end)
                                <dt class="col-6 text-muted fw-normal">{{ $subscription->cancel_at_period_end ? __('Access until') : __('Next renewal') }}</dt>
                                <dd class="col-6 text-end">{{ $subscription->current_period_end->format('d/m/Y') }}</dd>
                            @endif
                            @if($subscription->status === 'past_due')
                                <dt class="col-6 text-muted fw-normal">{{ __('Blocked on') }}</dt>
                                <dd class="col-6 text-end text-danger">{{ optional($subscription->grace_ends_at)->format('d/m/Y') }}</dd>
                            @endif
                            @if($subscription->pendingPlan)
                                <dt class="col-6 text-muted fw-normal">{{ __('Changes to') }}</dt>
                                <dd class="col-6 text-end">{{ $subscription->pendingPlan->name }} <small class="text-muted">({{ optional($subscription->current_period_end)->format('d/m/Y') }})</small></dd>
                            @endif
                        </dl>

                        @if($subscription->cancel_at_period_end)
                            <div class="alert alert-secondary small py-2">{{ __('Cancellation scheduled. You keep your plan until the end of the paid period.') }}</div>
                            <form method="POST" action="{{ route('billing.resume') }}">@csrf
                                <button class="btn btn-outline-primary btn-sm">{{ __('Keep my subscription') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('billing.cancel') }}" onsubmit="return confirm('{{ __('Cancel your subscription? You keep your plan until the end of the paid period.') }}');">@csrf
                                <button class="btn btn-outline-danger btn-sm">{{ __('Cancel subscription') }}</button>
                            </form>
                        @endif
                    @else
                        <p class="text-muted small">{{ __('You are on the free plan. Choose a plan below to unlock more.') }}</p>
                    @endif

                    @if($openInvoice)
                        <hr>
                        <div class="small">
                            <div class="fw-bold mb-1"><i class="bi bi-receipt me-1"></i>{{ __('Open invoice') }} {{ $openInvoice->number }}</div>
                            <div>{{ $money($openInvoice->amount_cents, $openInvoice->currency) }} · {{ __('due') }} {{ optional($openInvoice->due_at)->format('d/m/Y') }}</div>
                            <a href="{{ route('billing.invoice', $openInvoice) }}" class="btn btn-primary btn-sm mt-2">{{ __('View invoice') }}</a>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Usage --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100" style="border-radius: 12px;">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">{{ __('Usage') }}</h5>
                    @foreach($usage as $key => $row)
                        @php
                            $pct = $row['limit'] ? min(100, round($row['used'] / max(1, $row['limit']) * 100)) : 0;
                            $bar = $pct >= 100 ? 'danger' : ($pct >= 80 ? 'warning' : 'success');
                        @endphp
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small">
                                <span>{{ $usageLabels[$key] }}</span>
                                <span class="text-muted">{{ number_format($row['used'], 0, ',', '.') }} / {{ $row['limit'] === null ? '∞' : number_format($row['limit'], 0, ',', '.') }}</span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar bg-{{ $bar }}" style="width: {{ $row['limit'] === null ? 0 : $pct }}%"></div>
                            </div>
                        </div>
                    @endforeach
                    <div class="text-muted small">{{ __('Monthly counters restart on the 1st of each month.') }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Plans --}}
    <h4 class="fw-bold mb-3">{{ __('Plans') }}</h4>
    <div class="row g-4 mb-5">
        @foreach($plans as $plan)
            @php $isCurrent = $plan->id === $currentPlan->id; @endphp
            <div class="col-xl-3 col-md-6">
                <div class="card h-100 shadow-sm {{ $isCurrent ? 'border-primary' : 'border-0' }}" style="border-radius: 12px;">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex justify-content-between">
                            <h5 class="fw-bold">{{ $plan->name }}</h5>
                            @if($isCurrent)<span class="badge bg-primary align-self-start">{{ __('Your plan') }}</span>@endif
                        </div>
                        <div class="my-2">
                            <span class="fs-3 fw-bold">{{ $plan->isFree() ? __('Free') : $money($plan->price_cents, $plan->currency) }}</span>
                            @unless($plan->isFree())<span class="text-muted small">/ {{ __('month') }}</span>@endunless
                            @unless($plan->isFree())
                                <div class="small text-muted">{{ __('or :price per year', ['price' => $money($plan->priceFor('yearly'), $plan->currency)]) }}</div>
                            @endunless
                        </div>

                        <ul class="list-unstyled small flex-grow-1 mt-2">
                            @foreach($limitLabels as $key => $label)
                                <li class="mb-1"><i class="bi bi-check2 text-success me-1"></i>
                                    <strong>{{ $plan->limit($key) === null ? __('Unlimited') : number_format($plan->limit($key), 0, ',', '.') }}</strong> {{ strtolower($label) }}
                                </li>
                            @endforeach
                            @foreach($featureLabels as $key => $label)
                                <li class="mb-1 {{ $plan->hasFeature($key) ? '' : 'text-muted' }}">
                                    <i class="bi {{ $plan->hasFeature($key) ? 'bi-check2 text-success' : 'bi-x text-secondary' }} me-1"></i>{{ $label }}
                                </li>
                            @endforeach
                        </ul>

                        @if($isCurrent && $subscription && !$subscription->cancel_at_period_end)
                            <button class="btn btn-outline-secondary" disabled>{{ __('Current plan') }}</button>
                        @elseif($plan->isFree())
                            @if($subscription)
                                <form method="POST" action="{{ route('billing.cancel') }}">@csrf
                                    <button class="btn btn-outline-secondary w-100">{{ __('Switch to Free') }}</button>
                                </form>
                            @endif
                        @else
                            <form method="POST" action="{{ route('billing.subscribe') }}">
                                @csrf
                                <input type="hidden" name="plan" value="{{ $plan->slug }}">
                                <select name="cycle" class="form-select form-select-sm mb-2">
                                    <option value="monthly">{{ __('Monthly') }}</option>
                                    <option value="yearly">{{ __('Yearly (2 months free)') }}</option>
                                </select>
                                <button class="btn btn-primary w-100">{{ $subscription ? __('Choose this plan') : ($plan->trial_days > 0 ? __('Start :n-day free trial', ['n' => $plan->trial_days]) : __('Subscribe')) }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Invoices --}}
    <h4 class="fw-bold mb-3">{{ __('Invoices') }}</h4>
    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>{{ __('Number') }}</th><th>{{ __('Description') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($invoices as $invoice)
                    <tr>
                        <td class="font-monospace small">{{ $invoice->number }}</td>
                        <td class="small">{{ $invoice->description }}</td>
                        <td class="small">{{ optional($invoice->due_at)->format('d/m/Y') }}</td>
                        <td class="text-end">{{ $money($invoice->amount_cents, $invoice->currency) }}</td>
                        <td>
                            @if($invoice->status === 'paid')<span class="badge bg-success">{{ __('Paid') }}</span>
                            @elseif($invoice->status === 'void')<span class="badge bg-secondary">{{ __('Void') }}</span>
                            @elseif($invoice->isOverdue())<span class="badge bg-danger">{{ __('Overdue') }}</span>
                            @else<span class="badge bg-warning text-dark">{{ __('Open') }}</span>@endif
                        </td>
                        <td class="text-end"><a href="{{ route('billing.invoice', $invoice) }}" class="btn btn-sm btn-outline-primary">{{ __('View') }}</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No invoices yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
