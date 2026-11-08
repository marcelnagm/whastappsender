@extends('layouts.app-master')

@section('template_title', 'Typebot')

@section('content')
@php $o = '{' . '{'; $c = '}' . '}'; @endphp
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-robot me-1"></i> Typebot</h4>
  <p class="text-muted small">{{ __('Build the conversation flow in Typebot and let it answer your customers on every channel. A person can take over at any moment.') }}</p>
  @include('layouts.partials.messages')

  <div class="row g-3 mb-3">
    <div class="col-4"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ $stats['active'] ?? 0 }}</div><div class="small text-muted">{{ __('In the flow now') }}</div></div></div>
    <div class="col-4"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ $stats['finished'] ?? 0 }}</div><div class="small text-muted">{{ __('Finished the flow') }}</div></div></div>
    <div class="col-4"><div class="card p-3 text-center"><div class="fs-4 fw-bold">{{ $stats['handoff'] ?? 0 }}</div><div class="small text-muted">{{ __('Handed to a person') }}</div></div></div>
  </div>

  <form method="POST" action="{{ route('typebot.update') }}" class="card p-3 mb-3">
    @csrf @method('PUT')
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="tbOn" @checked(old('enabled', $cfg->enabled))><label class="form-check-label fw-semibold" for="tbOn">{{ __('Answer new conversations with the flow') }}</label></div>
    <div class="row g-2 mb-2">
      <div class="col-md-6"><label class="form-label small">{{ __('Typebot viewer address') }}</label><input name="base_url" class="form-control" required value="{{ old('base_url', $cfg->base_url) }}">@error('base_url')<div class="text-danger small">{{ $message }}</div>@enderror<div class="form-text">{{ __('https://typebot.io, or your own installation') }}</div></div>
      <div class="col-md-6"><label class="form-label small">{{ __('Public id of the bot') }}</label><input name="public_id" class="form-control" required value="{{ old('public_id', $cfg->public_id) }}" placeholder="my-flow-abc123">@error('public_id')<div class="text-danger small">{{ $message }}</div>@enderror<div class="form-text">{{ __('In Typebot: Share → the last part of the link') }}</div></div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-md-4"><label class="form-label small">{{ __('Number') }}</label><select name="instance_id" class="form-select"><option value="">{{ __('All numbers') }}</option>@foreach ($instances as $i)<option value="{{ $i->id }}" @selected(old('instance_id', $cfg->instance_id) == $i->id)>{{ $i->name }}</option>@endforeach</select></div>
      <div class="col-md-4"><label class="form-label small">{{ __('Words that call a person') }}</label><input name="handoff_keywords" class="form-control" value="{{ old('handoff_keywords', $cfg->handoff_keywords) }}" placeholder="atendente, humano, pessoa"></div>
      <div class="col-md-4"><label class="form-label small">{{ __('Start over after (minutes idle)') }}</label><input type="number" name="expire_minutes" min="5" max="10080" class="form-control" value="{{ old('expire_minutes', $cfg->expire_minutes) }}"></div>
    </div>
    <div class="row g-2 mb-2">
      <div class="col-md-4"><label class="form-label small">{{ __('When the flow ends') }}</label><select name="on_finish" class="form-select"><option value="none" @selected(old('on_finish', $cfg->on_finish) === 'none')>{{ __('Do nothing') }}</option><option value="handoff" @selected(old('on_finish', $cfg->on_finish) === 'handoff')>{{ __('Hand over to a person') }}</option></select></div>
      <div class="col-md-4"><label class="form-label small">{{ __('Hand over to') }}</label><select name="handoff_to" class="form-select"><option value="">{{ __('Normal distribution') }}</option>@foreach ($people as $p)<option value="{{ $p->id }}" @selected(old('handoff_to', $cfg->handoff_to) == $p->id)>{{ $p->name }}</option>@endforeach</select>@error('handoff_to')<div class="text-danger small">{{ $message }}</div>@enderror</div>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="only_outside_hours" value="1" id="tbH" @checked(old('only_outside_hours', $cfg->only_outside_hours))><label class="form-check-label small" for="tbH">{{ __('Only outside opening hours') }}</label></div></div>
    </div>
    <label class="form-label small">{{ __('Message sent when handing over (optional)') }}</label>
    <textarea name="handoff_message" rows="2" maxlength="1000" class="form-control mb-2" placeholder="{{ __('One moment, a person will continue with you.') }}">{{ old('handoff_message', $cfg->handoff_message) }}</textarea>
    <div class="d-flex gap-2"><button class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>
  </form>
  @if ($cfg->exists)
  <form method="POST" action="{{ route('typebot.test') }}" class="mb-3">@csrf<button class="btn btn-outline-secondary btn-sm">{{ __('Test the connection') }}</button></form>
  @endif

  <div class="card p-3 small">
    <h6>{{ __('How to hand over to a person from inside the flow') }}</h6>
    <ol class="mb-2">
      <li>{{ __('In Typebot create these variables (they are filled for you when the flow starts):') }} <code>name</code> <code>first_name</code> <code>phone</code> <code>chat_id</code> <code>handoff_url</code></li>
      <li>{{ __('Add an HTTP request block with method POST and URL') }} <code>{{ $o }}handoff_url{{ $c }}</code>. {{ __('Optional body') }}: <code>{"note": "{{ $o }}name{{ $c }} wants a quote"}</code></li>
      <li>{{ __('Before it, send a text such as "One moment, a person will continue" (or fill the message above).') }}</li>
    </ol>
    <p class="mb-1">{{ __('The conversation then leaves the flow: the AI agent is paused, it is distributed to the team, a note and a notification are created, the automation "The bot handed over to a person" and the webhook event bot.handoff fire. Answering from the CRM also ends the flow. Choice buttons arrive as a numbered list; the customer answers with the number.') }}</p>
  </div>
</div>
@endsection
