@extends('layouts.app-master')

@section('template_title', __('Opening hours'))

@section('content')
@php
  $days = \App\Services\AI\AiBusinessHours::days($cfg->business_days);
  $names = [1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'), 5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun')];
  $o = '{' . '{'; $c = '}' . '}';
  $awayPh = str_replace(['[[', ']]'], [$o, $c], __('Hi [[first_name]]! We are closed now. We open [[hours]] and will answer you then.'));
  $greetPh = str_replace(['[[', ']]'], [$o, $c], __('Hello [[first_name]], welcome to [[company]]! An attendant will be with you shortly.'));
@endphp
<div class="container py-4" style="max-width:820px">
  <h1 class="h3 fw-bold mb-1"><i class="bi bi-clock me-2"></i>{{ __('Opening hours') }}</h1>
  <p class="text-muted small">{{ __('When your team is available. Outside these hours customers can get an automatic away message; at the first message of a new conversation they can get a greeting. Automatic messages never count as an attendant answering, are not sent to contacts who opted out, and the greeting is skipped when the AI agent is answering.') }}</p>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <form method="POST" action="{{ route('hours.update') }}" class="card border-0 shadow-sm"><div class="card-body">
    @csrf @method('PUT')
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label small">{{ __('Timezone') }}</label>
        <select name="timezone" class="form-select">@foreach (array_unique(array_merge([$cfg->timezone], $timezones)) as $tz)<option value="{{ $tz }}" @selected(old('timezone', $cfg->timezone) === $tz)>{{ $tz }}</option>@endforeach</select></div>
      <div class="col-6 col-md-2"><label class="form-label small">{{ __('From') }}</label><input type="time" name="business_start" class="form-control" value="{{ old('business_start', $cfg->business_start) }}" required></div>
      <div class="col-6 col-md-2"><label class="form-label small">{{ __('To') }}</label><input type="time" name="business_end" class="form-control" value="{{ old('business_end', $cfg->business_end) }}" required></div>
      <div class="col-12 col-md-4"><label class="form-label small d-block">{{ __('Days') }}</label>
        @foreach ($names as $n => $l)<label class="me-2 small"><input type="checkbox" name="days[]" value="{{ $n }}" @checked(in_array($n, $days))> {{ $l }}</label>@endforeach</div>
    </div>

    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" name="away_enabled" value="1" id="away" @checked(old('away_enabled', $cfg->away_enabled))><label class="form-check-label fw-semibold" for="away">{{ __('Away message (outside opening hours)') }}</label></div>
    <textarea name="away_message" rows="3" class="form-control mb-2" maxlength="1000" placeholder="{{ $awayPh }}">{{ old('away_message', $cfg->away_message) }}</textarea>
    <div class="small mb-3">{{ __('Not again to the same conversation within') }} <input type="number" name="away_every_hours" min="1" max="168" value="{{ old('away_every_hours', $cfg->away_every_hours) }}" class="form-control form-control-sm d-inline-block" style="width:80px"> {{ __('hours') }}</div>

    <div class="form-check form-switch mb-1"><input class="form-check-input" type="checkbox" name="greeting_enabled" value="1" id="greet" @checked(old('greeting_enabled', $cfg->greeting_enabled))><label class="form-check-label fw-semibold" for="greet">{{ __('Greeting (first message of a new conversation)') }}</label></div>
    <textarea name="greeting_message" rows="3" class="form-control mb-2" maxlength="1000" placeholder="{{ $greetPh }}">{{ old('greeting_message', $cfg->greeting_message) }}</textarea>
    <p class="small text-muted">{{ __('Variables') }}: @foreach (['name', 'first_name', 'company', 'today', 'hours'] as $v)<code>{{ $o . $v . $c }}</code> @endforeach</p>

    <button class="btn btn-primary">{{ __('Save') }}</button>
  </div></form>
</div>
@endsection
