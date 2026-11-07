@extends('layouts.app-master')

@section('template_title', __('Products'))

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-box-seam me-2"></i>{{ __('Products') }}</h1>
    <a href="{{ route('pipeline.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-kanban me-1"></i>{{ __('Pipeline') }}</a>
  </div>
  <p class="text-muted small">{{ __('What you sell. Add products to a deal from the pipeline: the value of the deal becomes the total of its items. Changing a price here never changes deals that already have the product.') }}</p>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <form method="POST" action="{{ route('pipeline.products.store') }}" class="row g-2">@csrf
      <div class="col-md-4"><input name="name" class="form-control" placeholder="{{ __('Name') }}" required maxlength="120"></div>
      <div class="col-md-2"><input name="sku" class="form-control" placeholder="SKU" maxlength="40"></div>
      <div class="col-md-2"><input name="unit" class="form-control" placeholder="{{ __('Unit') }}" maxlength="20"></div>
      <div class="col-md-2"><input name="price" type="number" step="0.01" min="0" class="form-control" placeholder="{{ __('Price') }}" required></div>
      <div class="col-md-2"><button class="btn btn-primary w-100">{{ __('Add product') }}</button></div>
    </form>
  </div></div>

  <div class="card border-0 shadow-sm"><div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead><tr><th>{{ __('Name') }}</th><th>SKU</th><th>{{ __('Unit') }}</th><th class="text-end">{{ __('Price') }}</th><th>{{ __('Active') }}</th><th></th></tr></thead>
      <tbody>
        @forelse ($products as $p)
          <tr>
            <form method="POST" action="{{ route('pipeline.products.update', $p->id) }}" id="p{{ $p->id }}">@csrf @method('PUT')</form>
            <td><input form="p{{ $p->id }}" name="name" value="{{ $p->name }}" class="form-control form-control-sm" required maxlength="120"></td>
            <td><input form="p{{ $p->id }}" name="sku" value="{{ $p->sku }}" class="form-control form-control-sm" maxlength="40"></td>
            <td><input form="p{{ $p->id }}" name="unit" value="{{ $p->unit }}" class="form-control form-control-sm" maxlength="20"></td>
            <td><input form="p{{ $p->id }}" name="price" value="{{ $p->price }}" type="number" step="0.01" min="0" class="form-control form-control-sm text-end" required></td>
            <td><input form="p{{ $p->id }}" type="checkbox" name="active" value="1" class="form-check-input" @checked($p->active)></td>
            <td class="text-nowrap">
              <button form="p{{ $p->id }}" class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button>
              <button form="d{{ $p->id }}" class="btn btn-sm btn-outline-danger" onclick="return confirm('{{ __('Remove this product?') }}')"><i class="bi bi-trash"></i></button>
              <form id="d{{ $p->id }}" method="POST" action="{{ route('pipeline.products.destroy', $p->id) }}">@csrf @method('DELETE')</form>
            </td>
          </tr>
        @empty
          <tr><td colspan="6" class="text-muted text-center py-4">{{ __('No products yet.') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </div></div>
</div>
@endsection
