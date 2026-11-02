@extends('layouts.app-master')

@section('template_title', $form->exists ? __('Edit form') : __('New form'))

@section('content')
@php
  $rows = old('fields', $form->fields ?: []);
  $selChannels = old('reply_channels', (array) $form->reply_channels);
  $jsLabels = ['k0' => __('Label'), 'k1' => __('Meaning'), 'k2' => __('Type'), 'k3' => __('Placeholder'), 'k4' => __('Required'), 'k5' => __('Options (one per line)')];
@endphp
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-10">
    <a href="{{ route('forms.index') }}" class="small text-decoration-none">&larr; {{ __('Forms') }}</a>
    <h1 class="h3 fw-bold mb-3">{{ $form->exists ? __('Edit form') : __('New form') }}</h1>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

    @if ($form->exists)
    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">{{ __('Paste this on your website') }}</h2>
      <p class="small text-muted mb-2">{{ __('The form appears right where you paste the line. To choose the place, add an empty div with the attribute below before it.') }}</p>
      <input class="form-control font-monospace mb-2" readonly onclick="this.select()" value="{{ $form->snippet() }}">
      <input class="form-control font-monospace" readonly onclick="this.select()" value='<div data-sama-form="{{ $form->public_key }}"></div>'>
    </div></div>
    @endif

    <form method="POST" action="{{ $form->exists ? route('forms.update', $form->id) : route('forms.store') }}">
      @csrf
      @if ($form->exists) @method('PUT') @endif

      <div class="card shadow-sm mb-4"><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label">{{ __('Internal name') }}</label><input name="name" class="form-control" value="{{ old('name', $form->name) }}" required></div>
        <div class="col-md-6"><label class="form-label">{{ __('Title shown on the form') }}</label><input name="title" class="form-control" value="{{ old('title', $form->title) }}"></div>
        <div class="col-12"><label class="form-label">{{ __('Description') }}</label><input name="description" class="form-control" value="{{ old('description', $form->description) }}"></div>
        <div class="col-md-4"><label class="form-label">{{ __('Button text') }}</label><input name="submit_label" class="form-control" value="{{ old('submit_label', $form->submit_label) }}" placeholder="{{ __('Send') }}"></div>
        <div class="col-md-4"><label class="form-label">{{ __('Accent color') }}</label><input type="color" name="accent_color" class="form-control form-control-color" value="{{ old('accent_color', $form->accent_color ?: '#0d6efd') }}"></div>
        <div class="col-md-4"><label class="form-label">{{ __('Redirect after sending (optional)') }}</label><input type="url" name="redirect_url" class="form-control" value="{{ old('redirect_url', $form->redirect_url) }}" placeholder="https://"></div>
        <div class="col-12"><label class="form-label">{{ __('Message after sending') }}</label><input name="success_message" class="form-control" value="{{ old('success_message', $form->success_message) }}" placeholder="{{ __('Thank you! We will contact you shortly.') }}"></div>
      </div></div>

      <div class="card shadow-sm mb-4"><div class="card-body">
        <h2 class="h5">{{ __('Fields') }}</h2>
        <p class="small text-muted">{{ __('Mark what each field means so the CRM knows where to put it. The WhatsApp field is how the AI reaches the person.') }}</p>
        <div id="rows"></div>
        <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addRow"><i class="bi bi-plus-lg"></i> {{ __('Add field') }}</button>
      </div></div>

      <div class="card shadow-sm mb-4"><div class="card-body row g-3">
        <h2 class="h5 mb-0">{{ __('What happens after sending') }}</h2>
        <div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" name="ai_reply" value="1" id="ai" @checked(old('ai_reply', $form->ai_reply))><label class="form-check-label" for="ai">{{ __('Let the AI agent answer the person right away') }}</label></div>
        <div class="col-md-6">
          <label class="form-label">{{ __('WhatsApp number that answers') }}</label>
          <select name="instance_id" class="form-select">
            <option value="">{{ __('Any connected number') }}</option>
            @foreach ($instances as $i)<option value="{{ $i->id }}" @selected((int) old('instance_id', $form->instance_id) === $i->id)>{{ $i->name ?: $i->instance_name }}</option>@endforeach
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">{{ __('Channels the AI may use') }}</label>
          <div>
            @foreach ($channels as $c)<div class="form-check form-check-inline"><input type="checkbox" class="form-check-input" name="reply_channels[]" value="{{ $c }}" id="ch{{ $c }}" @checked(in_array($c, $selChannels))><label class="form-check-label" for="ch{{ $c }}">{{ ucfirst($c) }}</label></div>@endforeach
          </div>
          <div class="form-text">{{ __('None selected = all. WhatsApp is used first; other channels only if the person already talks to you there and the reply window is open.') }}</div>
        </div>
        <div class="col-12"><label class="form-label">{{ __('Allowed websites (one domain per line, empty = any site)') }}</label><textarea name="allowed_origins" rows="2" class="form-control" placeholder="example.com">{{ old('allowed_origins', $form->allowed_origins) }}</textarea></div>
        <div class="col-12 form-check ms-2"><input type="checkbox" class="form-check-input" name="active" value="1" id="act" @checked(old('active', $form->active))><label class="form-check-label" for="act">{{ __('Active') }}</label></div>
      </div></div>

      <div class="d-flex gap-2 mb-4">
        <button class="btn btn-primary">{{ __('Save') }}</button>
        @if ($form->exists)<button form="del" class="btn btn-outline-danger ms-auto" onclick="return confirm('{{ __('Remove this form and its submissions?') }}')">{{ __('Remove') }}</button>@endif
      </div>
    </form>
    @if ($form->exists)<form id="del" method="POST" action="{{ route('forms.destroy', $form->id) }}">@csrf @method('DELETE')</form>@endif
  </div></div>
</div>

<script>
(function () {
  var rows = @json(array_values($rows)), maps = @json($maps), types = @json($types);
  var labels = { name: @json(__('Contact name')), phone: @json(__('WhatsApp number')), email: @json(__('Email')), message: @json(__('Message for the AI')), '': @json(__('Extra information')) };
  var typeLabels = { text: @json(__('Text')), email: @json(__('Email')), tel: @json(__('Phone')), textarea: @json(__('Long text')), select: @json(__('Choice')) };
  var L = @json($jsLabels);
  var box = document.getElementById('rows');
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function add(r) {
    r = r || { label: '', type: 'text', map: '', required: false, options: [], placeholder: '' };
    var i = box.children.length, d = document.createElement('div');
    d.className = 'row g-2 align-items-end border rounded p-2 mb-2';
    var opts = Array.isArray(r.options) ? r.options.join('\n') : (r.options || '');
    d.innerHTML =
      '<div class="col-md-3"><label class="form-label small">' + L.k0 + '</label><input class="form-control form-control-sm" data-k="label" value="' + esc(r.label) + '"></div>' +
      '<div class="col-md-2"><label class="form-label small">' + L.k1 + '</label><select class="form-select form-select-sm" data-k="map">' + ['', 'name', 'phone', 'email', 'message'].map(function (m) { return '<option value="' + m + '"' + ((r.map || '') === m ? ' selected' : '') + '>' + esc(labels[m]) + '</option>'; }).join('') + '</select></div>' +
      '<div class="col-md-2"><label class="form-label small">' + L.k2 + '</label><select class="form-select form-select-sm" data-k="type">' + types.map(function (t) { return '<option value="' + t + '"' + (r.type === t ? ' selected' : '') + '>' + esc(typeLabels[t]) + '</option>'; }).join('') + '</select></div>' +
      '<div class="col-md-2"><label class="form-label small">' + L.k3 + '</label><input class="form-control form-control-sm" data-k="placeholder" value="' + esc(r.placeholder) + '"></div>' +
      '<div class="col-md-1"><div class="form-check"><input type="checkbox" class="form-check-input" data-k="required" value="1"' + (r.required ? ' checked' : '') + '><label class="form-check-label small">' + L.k4 + '</label></div></div>' +
      '<div class="col-md-2 text-end"><button type="button" class="btn btn-sm btn-outline-danger" data-del>&times;</button></div>' +
      '<div class="col-12" data-opts style="display:none"><label class="form-label small">' + L.k5 + '</label><textarea class="form-control form-control-sm" rows="3" data-k="options">' + esc(opts) + '</textarea></div>';
    box.appendChild(d);
    var type = d.querySelector('[data-k=type]'), map = d.querySelector('[data-k=map]');
    function sync() { d.querySelector('[data-opts]').style.display = type.value === 'select' ? '' : 'none'; }
    map.addEventListener('change', function () { var t = { phone: 'tel', email: 'email', message: 'textarea' }[map.value]; if (t) { type.value = t; } sync(); });
    type.addEventListener('change', sync); sync();
    d.querySelector('[data-del]').addEventListener('click', function () { d.remove(); renumber(); });
    renumber();
  }
  function renumber() {
    Array.prototype.forEach.call(box.children, function (d, i) {
      d.querySelectorAll('[data-k]').forEach(function (el) { el.name = 'fields[' + i + '][' + el.getAttribute('data-k') + ']'; });
    });
  }
  rows.forEach(add);
  document.getElementById('addRow').addEventListener('click', function () { add(); });
})();
</script>
@endsection
