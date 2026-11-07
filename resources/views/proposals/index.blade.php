@extends('layouts.app-master')

@section('template_title', __('Proposals'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $cls = ['draft' => 'secondary', 'sent' => 'primary', 'viewed' => 'info', 'accepted' => 'success', 'declined' => 'danger']; $cur = config('crm.currency', 'R$'); @endphp
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-file-earmark-text me-2"></i>{{ __('Proposals') }}</h1>
    <a href="{{ route('pipeline.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-kanban me-1"></i>{{ __('Pipeline') }}</a>
  </div>
  <p class="text-muted small">{{ __('Create a proposal from the window of a deal that has items, copy the link and send it to the customer, who can accept or decline online.') }}</p>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif

  <div class="row g-2 mb-3">
    <div class="col-4"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Waiting for answer') }}</div><strong>{{ $summary['open'] }}</strong></div></div></div>
    <div class="col-4"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Accepted') }}</div><strong>{{ $summary['accepted'] }}</strong> · {{ $cur }} {{ $money($summary['accepted_value']) }}</div></div></div>
    <div class="col-4"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Declined') }}</div><strong>{{ $summary['declined'] }}</strong></div></div></div>
  </div>

  <div class="card border-0 shadow-sm"><div class="list-group list-group-flush">
    @forelse ($proposals as $p)
      <a href="{{ route('proposals.show', $p->id) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
        <span><span class="text-muted">#{{ $p->number }}</span> <strong>{{ $p->title }}</strong>
          <span class="badge bg-{{ $cls[$p->status] }} ms-1">{{ $p->isExpired() ? __('Expired') : __(ucfirst($p->status)) }}</span></span>
        <span>{{ $cur }} {{ $money($p->total) }}</span>
      </a>
    @empty
      <div class="list-group-item text-muted">{{ __('No proposals yet.') }}</div>
    @endforelse
  </div></div>
</div>
@endsection
