@extends('layouts.app-master')

@section('template_title', __('Segments'))

@section('content')
@php
  $catalogJson = json_encode($catalog);
  $opLabels = ['eq' => __('is'), 'neq' => __('is not'), 'contains' => __('contains'), 'gte' => __('at least'), 'lte' => __('at most'), 'filled' => __('is filled'), 'empty' => __('is empty')];
  $opJson = json_encode($opLabels);
@endphp
<div class="container py-4">
  <h1 class="h3 fw-bold mb-3"><i class="bi bi-funnel me-2"></i>{{ __('Segments') }}</h1>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6 class="fw-bold"><i class="bi bi-sliders me-1"></i>{{ __('Custom fields') }}</h6>
        <p class="small text-muted">{{ __('Your own data on each contact (city, plan, birthday...). They show on the contact page, can be filled by import (column with the field name) and used in segments.') }}</p>
        <ul class="list-group list-group-flush mb-3">
          @forelse ($fields as $f)
            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
              <span>{{ $f->label }} <span class="badge bg-light text-muted border">{{ __($f->type) }}</span>
                @if ($f->type === 'select')<span class="small text-muted">{{ implode(', ', (array) $f->options) }}</span>@endif</span>
              <form method="POST" action="{{ route('audience.fields.destroy', $f->id) }}" onsubmit="return confirm('{{ __('Remove this field?') }}')">@csrf @method('DELETE')
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
            </li>
          @empty
            <li class="list-group-item px-0 text-muted small">{{ __('No custom fields yet.') }}</li>
          @endforelse
        </ul>
        <form method="POST" action="{{ route('audience.fields.store') }}" class="row g-2">@csrf
          <div class="col-6"><input name="label" class="form-control form-control-sm" placeholder="{{ __('Field name') }}" required maxlength="60"></div>
          <div class="col-6"><select name="type" class="form-select form-select-sm" id="ftype">@foreach ($types as $t)<option value="{{ $t }}">{{ __($t) }}</option>@endforeach</select></div>
          <div class="col-12 d-none" id="fopts"><input name="options" class="form-control form-control-sm" placeholder="{{ __('Choices, separated by commas') }}"></div>
          <div class="col-12"><button class="btn btn-primary btn-sm">{{ __('Add field') }}</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-lg-7">
      <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6 class="fw-bold"><i class="bi bi-people me-1"></i>{{ __('Saved segments') }}</h6>
        <p class="small text-muted">{{ __('Pick a segment as the audience when approving a campaign.') }}</p>
        <ul class="list-group list-group-flush">
          @forelse ($segments as $s)
            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
              <span><strong>{{ $s->name }}</strong> <span class="badge bg-primary-subtle text-primary">{{ $s->size }}</span></span>
              <span class="d-flex gap-1">
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('audience.segments.export', $s->id) }}"><i class="bi bi-download"></i></a>
                <form method="POST" action="{{ route('audience.segments.destroy', $s->id) }}" onsubmit="return confirm('{{ __('Remove this segment?') }}')">@csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
              </span>
            </li>
          @empty
            <li class="list-group-item px-0 text-muted small">{{ __('No segments yet.') }}</li>
          @endforelse
        </ul>
      </div></div>

      <div class="card border-0 shadow-sm"><div class="card-body">
        <h6 class="fw-bold">{{ __('New segment') }}</h6>
        <form method="POST" action="{{ route('audience.segments.store') }}" id="seg-form" data-catalog="{{ $catalogJson }}" data-ops="{{ $opJson }}" data-preview="{{ route('audience.preview') }}">@csrf
          <input name="name" class="form-control mb-2" placeholder="{{ __('Segment name') }}" required maxlength="80">
          <div id="conds" class="mb-2"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary mb-2" id="add-cond"><i class="bi bi-plus"></i> {{ __('Add condition') }}</button>
          <div class="alert alert-info border-0 py-2 small"><strong id="seg-count">–</strong> {{ __('contact(s) match') }}</div>
          <button class="btn btn-primary btn-sm">{{ __('Save segment') }}</button>
        </form>
      </div></div>
    </div>
  </div>
</div>
<script>
(function () {
  var f = document.getElementById('seg-form'), cat = JSON.parse(f.dataset.catalog), ops = JSON.parse(f.dataset.ops), box = document.getElementById('conds'), n = 0, t;
  document.getElementById('ftype').addEventListener('change', function () { document.getElementById('fopts').classList.toggle('d-none', this.value !== 'select'); });
  function el(tag, attrs) { var e = document.createElement(tag); Object.keys(attrs || {}).forEach(function (k) { e[k] = attrs[k]; }); return e; }
  function row() {
    var i = n++, r = el('div', { className: 'row g-2 mb-2 align-items-center' });
    var fs = el('select', { className: 'form-select form-select-sm', name: 'filters[' + i + '][field]' });
    cat.forEach(function (c) { fs.appendChild(el('option', { value: c.field, textContent: c.label })); });
    var os = el('select', { className: 'form-select form-select-sm', name: 'filters[' + i + '][op]' });
    var vc = el('div', { className: 'col-4' });
    function cur() { return cat.filter(function (c) { return c.field === fs.value; })[0]; }
    function rebuild() {
      var c = cur(); os.innerHTML = '';
      c.ops.forEach(function (o) { os.appendChild(el('option', { value: o, textContent: ops[o] || o })); });
      vc.innerHTML = '';
      var v;
      if (c.type === 'boolean') { v = el('select', { className: 'form-select form-select-sm' }); [['1', '✔'], ['0', '✘']].forEach(function (p) { v.appendChild(el('option', { value: p[0], textContent: p[1] })); }); }
      else if (c.options && c.options.length) { v = el('select', { className: 'form-select form-select-sm' }); c.options.forEach(function (o) { v.appendChild(el('option', { value: o, textContent: o })); }); }
      else { v = el('input', { className: 'form-control form-control-sm', type: c.type === 'number' ? 'number' : (c.type === 'date' ? 'date' : 'text') }); if (c.type === 'number') v.step = 'any'; }
      v.name = 'filters[' + i + '][value]'; vc.appendChild(v); v.addEventListener('input', soon); v.addEventListener('change', soon);
      soon();
    }
    function toggleVal() { vc.style.visibility = (os.value === 'filled' || os.value === 'empty') ? 'hidden' : 'visible'; soon(); }
    fs.addEventListener('change', rebuild); os.addEventListener('change', toggleVal);
    var rm = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', innerHTML: '<i class="bi bi-x"></i>' });
    rm.addEventListener('click', function () { r.remove(); soon(); });
    var c1 = el('div', { className: 'col-4' }), c2 = el('div', { className: 'col-3' }), c4 = el('div', { className: 'col-1' });
    c1.appendChild(fs); c2.appendChild(os); c4.appendChild(rm); r.appendChild(c1); r.appendChild(c2); r.appendChild(vc); r.appendChild(c4);
    box.appendChild(r); rebuild();
  }
  function soon() { clearTimeout(t); t = setTimeout(refresh, 350); }
  function refresh() {
    fetch(f.dataset.preview, { method: 'POST', headers: { 'X-CSRF-TOKEN': f.querySelector('[name=_token]').value, 'Accept': 'application/json' }, body: new FormData(f) })
      .then(function (r) { return r.json(); }).then(function (d) { document.getElementById('seg-count').textContent = d.conditions ? d.count : '–'; }).catch(function () {});
  }
  document.getElementById('add-cond').addEventListener('click', row);
  row();
})();
</script>
@endsection
