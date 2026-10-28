@extends('layouts.app-master')

@section('template_title', $profile->exists ? __('Edit agent') : __('New agent'))

@section('content')
@php
  $days = old('business_days', $profile->business_days ? explode(',', $profile->business_days) : ['1','2','3','4','5']);
  $dayNames = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
  $hoursOn = old('_token') ? (bool) old('business_hours_only') : (bool) $profile->business_hours_only;
  $enabledOn = old('_token') ? (bool) old('enabled') : (bool) $profile->enabled;
@endphp
<div class="container-fluid py-4"><div class="row justify-content-center"><div class="col-lg-9">
  <h1 class="h3 fw-bold mb-4"><i class="bi bi-robot text-primary me-2"></i>{{ $profile->exists ? __('Edit agent') : __('New agent') }}</h1>

  @if ($errors->any())
    <div class="alert alert-danger border-0"><ul class="mb-0">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>
  @endif

  <form method="POST" id="agentForm" action="{{ $profile->exists ? route('ai.update', $profile) : route('ai.store') }}">
    @csrf
    @if ($profile->exists) @method('PUT') @endif
    <input type="hidden" name="profile_id" value="{{ $profile->id }}">

    <div class="card shadow-sm mb-4"><div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label small fw-bold text-uppercase" for="name">{{ __('Agent name') }}</label>
        <input class="form-control" id="name" name="name" required maxlength="80" value="{{ old('name', $profile->name) }}">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-bold text-uppercase" for="instance_id">{{ __('Answers for') }}</label>
        <select class="form-select" id="instance_id" name="instance_id">
          <option value="">{{ __('Any instance') }}</option>
          @foreach ($instances as $i)
            <option value="{{ $i->id }}" @selected((string) old('instance_id', $profile->instance_id) === (string) $i->id)>{{ $i->name ?? $i->instance_name }}</option>
          @endforeach
        </select>
        <div class="form-text">{{ __('The reply always leaves through the instance that received the message.') }}</div>
      </div>
      <div class="col-12 form-check form-switch ms-2">
        <input type="hidden" name="enabled" value="0">
        <input class="form-check-input" type="checkbox" role="switch" id="enabled" name="enabled" value="1" {{ $enabledOn ? 'checked' : '' }}>
        <label class="form-check-label" for="enabled">{{ __('Agent enabled') }}</label>
      </div>
    </div></div>

    <div class="card shadow-sm mb-4"><div class="card-header fw-semibold">{{ __('Model') }}</div><div class="card-body row g-3">
      <div class="col-md-5">
        <label class="form-label small fw-bold text-uppercase" for="provider">{{ __('Provider') }}</label>
        <select class="form-select" id="provider" name="provider">
          @foreach ($providers as $p)
            <option value="{{ $p['key'] }}" @selected(old('provider', $profile->provider) === $p['key'])>{{ $p['label'] }}</option>
          @endforeach
        </select>
      </div>
      <div class="col-md-7">
        <label class="form-label small fw-bold text-uppercase" for="model">{{ __('Model') }}</label>
        <input class="form-control" id="model" name="model" list="modelList" required maxlength="120" value="{{ old('model', $profile->model) }}">
        <datalist id="modelList"></datalist>
        <div class="form-text">{{ __('Pick a suggestion or type any model id supported by the provider.') }}</div>
      </div>
      <div class="col-12" id="keyBox">
        <label class="form-label small fw-bold text-uppercase" for="api_key">{{ __('API key') }}</label>
        <input type="password" class="form-control" id="api_key" name="api_key" autocomplete="new-password" placeholder="{{ $profile->keyHint() ? __('Saved') . ': ' . $profile->keyHint() . ' — ' . __('leave blank to keep') : '' }}">
        <div class="form-text"><span id="keyDocs"></span> <span id="platformKey" class="d-none text-success">{{ __('A platform key is configured: you can leave this empty.') }}</span></div>
        @if ($profile->keyHint())
          <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="clear_key" id="clear_key" value="1"><label class="form-check-label small" for="clear_key">{{ __('Remove the saved key') }}</label></div>
        @endif
      </div>
      <div class="col-12 d-none" id="urlBox">
        <label class="form-label small fw-bold text-uppercase" for="base_url">{{ __('Endpoint URL') }}</label>
        <input type="url" class="form-control" id="base_url" name="base_url" placeholder="http://localhost:11434/v1" value="{{ old('base_url', $profile->base_url) }}">
        <div class="form-text">{{ __('Ollama or any OpenAI-compatible server. Private/local addresses only work when the server admin allows them.') }}</div>
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-bold text-uppercase" for="temperature">{{ __('Temperature') }}</label>
        <input type="number" class="form-control" id="temperature" name="temperature" min="0" max="2" step="0.01" value="{{ old('temperature', $profile->temperature ?? 0.7) }}">
      </div>
      <div class="col-md-6">
        <label class="form-label small fw-bold text-uppercase" for="max_tokens">{{ __('Max Tokens') }}</label>
        <input type="number" class="form-control" id="max_tokens" name="max_tokens" min="50" max="8000" value="{{ old('max_tokens', $profile->max_tokens ?? 1024) }}">
      </div>
      <div class="col-12 d-flex align-items-center gap-3">
        <button type="button" class="btn btn-outline-secondary" id="testBtn"><i class="bi bi-plug me-1"></i>{{ __('Test connection') }}</button>
        <span id="testResult" class="small"></span>
      </div>
    </div></div>

    <div class="card shadow-sm mb-4"><div class="card-header fw-semibold">{{ __('Behaviour (prompt)') }}</div><div class="card-body">
      <textarea class="form-control" name="system_prompt" rows="9" placeholder="{{ $defaultPrompt }}">{{ old('system_prompt', $profile->system_prompt) }}</textarea>
      <div class="form-text">{{ __('Instructions that define how this agent talks and behaves. Empty uses the default prompt.') }}</div>
    </div></div>

    <div class="card shadow-sm mb-4"><div class="card-header fw-semibold">{{ __('Working hours') }}</div><div class="card-body row g-3">
      <div class="col-12 form-check form-switch ms-2">
        <input type="hidden" name="business_hours_only" value="0">
        <input class="form-check-input" type="checkbox" role="switch" id="business_hours_only" name="business_hours_only" value="1" {{ $hoursOn ? 'checked' : '' }}>
        <label class="form-check-label" for="business_hours_only">{{ __('Reply only during these hours') }}</label>
        <div class="form-text">{{ __('Outside the window the AI stays silent. The rule applies to every instance this agent covers.') }}</div>
      </div>
      <div class="col-12" id="hoursBox">
        <div class="mb-3">
          @foreach ($dayNames as $n => $label)
            <div class="form-check form-check-inline">
              <input class="form-check-input" type="checkbox" name="business_days[]" id="d{{ $n }}" value="{{ $n }}" {{ in_array((string) $n, array_map('strval', (array) $days), true) ? 'checked' : '' }}>
              <label class="form-check-label" for="d{{ $n }}">{{ $label }}</label>
            </div>
          @endforeach
        </div>
        <div class="row g-3">
          <div class="col-md-3"><label class="form-label small fw-bold text-uppercase" for="business_start">{{ __('From') }}</label>
            <input type="time" class="form-control" id="business_start" name="business_start" value="{{ old('business_start', $profile->business_start ?: sprintf('%02d:00', $hoursDefaults['start'])) }}"></div>
          <div class="col-md-3"><label class="form-label small fw-bold text-uppercase" for="business_end">{{ __('Until') }}</label>
            <input type="time" class="form-control" id="business_end" name="business_end" value="{{ old('business_end', $profile->business_end ?: sprintf('%02d:00', $hoursDefaults['end'])) }}"></div>
          <div class="col-md-6"><label class="form-label small fw-bold text-uppercase" for="timezone">{{ __('Timezone') }}</label>
            <input class="form-control" id="timezone" name="timezone" list="tzList" value="{{ old('timezone', $profile->timezone ?: $hoursDefaults['timezone']) }}">
            <datalist id="tzList">@foreach (['America/Sao_Paulo','America/Manaus','America/Boa_Vista','America/Fortaleza','America/New_York','America/Mexico_City','Europe/Lisbon','Europe/Madrid','UTC'] as $tz)<option value="{{ $tz }}">@endforeach</datalist></div>
        </div>
      </div>
    </div></div>

    <div class="d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>{{ __('Save') }}</button>
      <a href="{{ route('ai.index') }}" class="btn btn-outline-secondary">{{ __('Cancel') }}</a>
    </div>
  </form>
</div></div></div>

<script>
(function () {
  const providers = @json($providers);
  const $ = id => document.getElementById(id);
  const sel = $('provider');
  function apply(changed) {
    const p = providers.find(x => x.key === sel.value) || {};
    $('modelList').innerHTML = (p.models || []).map(m => '<option value="' + m + '">').join('');
    if (changed && p.models && p.models.length) $('model').value = p.models[0];
    $('urlBox').classList.toggle('d-none', !p.editable_url);
    if (changed && p.editable_url && !$('base_url').value) $('base_url').value = p.base_url || '';
    $('keyBox').classList.toggle('d-none', p.needs_key === false);
    $('platformKey').classList.toggle('d-none', !p.platform_key);
    $('keyDocs').innerHTML = p.docs ? '<a href="' + p.docs + '" target="_blank" rel="noopener">' + @json(__('Get an API key')) + '</a>' : '';
  }
  sel.addEventListener('change', () => apply(true)); apply(false);

  const hours = $('business_hours_only');
  const toggleHours = () => $('hoursBox').style.opacity = hours.checked ? 1 : .45;
  hours.addEventListener('change', toggleHours); toggleHours();

  $('testBtn').addEventListener('click', async () => {
    const out = $('testResult'); out.className = 'small text-muted'; out.textContent = @json(__('Testing...'));
    const fd = new FormData($('agentForm')); fd.delete('_method');
    try {
      const r = await fetch(@json(route('ai.test')), {method: 'POST', body: fd, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
      const j = await r.json();
      if (r.status === 422) { out.className = 'small text-danger'; out.textContent = Object.values(j.errors || {}).flat().join(' '); return; }
      out.className = 'small ' + (j.ok ? 'text-success' : 'text-danger');
      out.textContent = j.ok ? '✓ ' + @json(__('Connected')) + ' (' + j.ms + ' ms): ' + j.reply : '✗ ' + j.error;
    } catch (e) { out.className = 'small text-danger'; out.textContent = '✗ ' + e.message; }
  });
})();
</script>
@endsection
