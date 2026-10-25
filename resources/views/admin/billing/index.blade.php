@extends('layouts.app-master')

@section('content')
@php
    $money = fn ($cents, $cur = 'BRL') => ($cur === 'BRL' ? 'R$ ' : $cur . ' ') . number_format($cents / 100, 2, ',', '.');
    $badge = ['pending' => 'warning', 'trialing' => 'info', 'active' => 'success', 'past_due' => 'warning', 'suspended' => 'danger', 'canceled' => 'secondary', 'expired' => 'secondary'];
@endphp
<div class="container py-4">
    @include('layouts.partials.messages')
    <h2 class="fw-bold mb-3"><i class="bi bi-cash-coin text-primary me-2"></i>{{ __('Billing') }}</h2>

    <div class="mb-3 d-flex flex-wrap gap-2">
        <a href="{{ route('admin.billing.index') }}" class="btn btn-sm {{ !$status ? 'btn-dark' : 'btn-outline-dark' }}">{{ __('All') }}</a>
        @foreach(['pending','trialing','active','past_due','suspended','canceled','expired'] as $st)
            <a href="{{ route('admin.billing.index', ['status' => $st]) }}" class="btn btn-sm {{ $status === $st ? 'btn-dark' : 'btn-outline-dark' }}">{{ $st }} ({{ $counts[$st] ?? 0 }})</a>
        @endforeach
    </div>

    <div class="card border-0 shadow-sm mb-4" style="border-radius: 12px;">
        <div class="table-responsive">
            <table class="table align-middle mb-0 small">
                <thead class="table-light"><tr>
                    <th>{{ __('User') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Status') }}</th><th>{{ __('Period ends') }}</th><th>{{ __('Open invoice') }}</th><th></th>
                </tr></thead>
                <tbody>
                @forelse($subs as $sub)
                    @php $inv = optional($open->get($sub->id))->first(); @endphp
                    <tr>
                        <td>{{ $sub->user->name ?? '#' . $sub->user_id }}<div class="text-muted">{{ $sub->user->email ?? '' }}</div></td>
                        <td>{{ $sub->plan->name ?? '-' }} <span class="text-muted">({{ $sub->cycle }})</span></td>
                        <td><span class="badge bg-{{ $badge[$sub->status] ?? 'secondary' }}">{{ $sub->status }}</span></td>
                        <td>{{ optional($sub->current_period_end)->format('d/m/Y') }}</td>
                        <td>@if($inv){{ $inv->number }} · {{ $money($inv->amount_cents, $inv->currency) }} · {{ optional($inv->due_at)->format('d/m/Y') }}@else <span class="text-muted">-</span>@endif</td>
                        <td class="text-end">
                            @if($inv)
                                <form method="POST" action="{{ route('admin.billing.paid', $inv) }}" onsubmit="return confirm('{{ __('Confirm payment received?') }}');">@csrf
                                    <button class="btn btn-sm btn-success">{{ __('Mark as paid') }}</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">{{ __('No subscriptions.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    {{ $subs->links() }}

    <div class="card border-0 shadow-sm" style="border-radius: 12px;">
        <div class="card-body">
            <h6 class="fw-bold">{{ __('Grant a plan without charging') }}</h6>
            <form method="POST" action="{{ route('admin.billing.grant') }}" class="row g-2 align-items-end">@csrf
                <div class="col-md-4"><label class="form-label small">{{ __('User') }}</label>
                    <select name="user_id" class="form-select form-select-sm" required>
                        @foreach($users as $u)<option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>@endforeach
                    </select></div>
                <div class="col-md-3"><label class="form-label small">{{ __('Plan') }}</label>
                    <select name="plan" class="form-select form-select-sm">@foreach($plans as $p)<option value="{{ $p->slug }}">{{ $p->name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label small">{{ __('Days') }}</label>
                    <input type="number" name="days" value="30" min="1" max="3650" class="form-control form-control-sm"></div>
                <div class="col-md-3"><button class="btn btn-primary btn-sm w-100">{{ __('Grant') }}</button></div>
            </form>
        </div>
    </div>
</div>
@endsection
