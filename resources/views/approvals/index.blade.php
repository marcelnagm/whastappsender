@extends('layouts.app-master')

@section('template_title', __('Approvals'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-10">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-check2-square me-2"></i>{{ __('Approvals') }}</h1>
    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif

    <div class="card shadow-sm mb-4">
      <div class="card-header bg-white fw-bold">{{ __('Waiting for review') }}</div>
      <div class="list-group list-group-flush">
        @forelse ($pending as $i)
          <a href="{{ route('approvals.show', $i->id) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
            <span><strong>{{ $i->name }}</strong><span class="d-block small text-muted">{{ \Illuminate\Support\Str::limit($i->text, 120) }}</span></span>
            <span class="badge bg-warning text-dark">{{ __('Review') }}</span>
          </a>
        @empty
          <div class="list-group-item text-muted text-center py-4">{{ __('Nothing waiting for review.') }}</div>
        @endforelse
      </div>
    </div>

    <div class="card shadow-sm">
      <div class="card-header bg-white fw-bold">{{ __('Recent decisions') }}</div>
      <div class="table-responsive"><table class="table align-middle mb-0">
        <tbody>
        @foreach ($recent as $i)
          <tr>
            <td><a href="{{ route('approvals.show', $i->id) }}">{{ $i->name }}</a></td>
            <td><span class="badge bg-{{ ['approved' => 'success', 'launched' => 'primary', 'rejected' => 'danger'][$i->approval_status] ?? 'secondary' }}">{{ __(ucfirst($i->approval_status)) }}</span></td>
            <td class="small text-muted">{{ $i->audience_count !== null ? __(':n contact(s)', ['n' => $i->audience_count]) : '' }}</td>
            <td class="small text-muted">{{ optional($i->approved_at)->format('d/m/Y H:i') }}</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
    </div>
  </div></div>
</div>
@endsection
