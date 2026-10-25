@extends('layouts.app-master')

@section('content')
@php $money = fn ($cents, $cur = 'BRL') => ($cur === 'BRL' ? 'R$ ' : $cur . ' ') . number_format($cents / 100, 2, ',', '.'); @endphp
<div class="container py-4" style="max-width: 760px;">
    <a href="{{ route('billing.index') }}" class="small text-decoration-none"><i class="bi bi-arrow-left me-1"></i>{{ __('Back to plan and billing') }}</a>

    <div class="card border-0 shadow-sm mt-3" style="border-radius: 12px;">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4 class="fw-bold mb-0">{{ __('Invoice') }} {{ $invoice->number }}</h4>
                    <div class="text-muted small">{{ $invoice->created_at->format('d/m/Y') }}</div>
                </div>
                @if($invoice->status === 'paid')<span class="badge bg-success fs-6">{{ __('Paid') }}</span>
                @elseif($invoice->status === 'void')<span class="badge bg-secondary fs-6">{{ __('Void') }}</span>
                @elseif($invoice->isOverdue())<span class="badge bg-danger fs-6">{{ __('Overdue') }}</span>
                @else<span class="badge bg-warning text-dark fs-6">{{ __('Open') }}</span>@endif
            </div>

            <table class="table small">
                <tr><td class="text-muted">{{ __('Description') }}</td><td class="text-end">{{ $invoice->description }}</td></tr>
                <tr><td class="text-muted">{{ __('Period') }}</td><td class="text-end">{{ optional($invoice->period_start)->format('d/m/Y') }} – {{ optional($invoice->period_end)->format('d/m/Y') }}</td></tr>
                <tr><td class="text-muted">{{ __('Due') }}</td><td class="text-end">{{ optional($invoice->due_at)->format('d/m/Y') }}</td></tr>
                @if($invoice->credit_cents > 0)
                    <tr><td class="text-muted">{{ __('Credit for unused time') }}</td><td class="text-end">− {{ $money($invoice->credit_cents, $invoice->currency) }}</td></tr>
                @endif
                @if($invoice->paid_at)
                    <tr><td class="text-muted">{{ __('Paid on') }}</td><td class="text-end">{{ $invoice->paid_at->format('d/m/Y H:i') }}</td></tr>
                @endif
                <tr class="fw-bold fs-5"><td>{{ __('Total') }}</td><td class="text-end">{{ $money($invoice->amount_cents, $invoice->currency) }}</td></tr>
            </table>

            @if($invoice->isOpen())
                <div class="alert alert-info small mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    {{ __('Online payment (Pix, card and boleto) is coming soon. Until then, contact support to pay this invoice; access is restored as soon as the payment is confirmed.') }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
