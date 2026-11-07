@extends('layouts.app-master')

@section('template_title', __('Follow-up sequences'))

@section('content')
@php
  $o = '{' . '{'; $c = '}' . '}';
  $reasons = ['replied' => __('Customer replied'), 'manual' => __('Stopped by a person'), 'window' => __('Reply window closed'), 'opt_out' => __('Opted out'), 'failed' => __('Failed'), 'removed' => __('Sequence removed')];
  $replied = $stats->where('stop_reason', 'replied')->sum('n');
  $done = $stats->where('status', 'completed')->sum('n');
@endphp
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-arrow-repeat me-1"></i> {{ __('Follow-up sequences') }}</h4>
  <p class="text-muted small">{{ __('Messages sent one after the other with a wait between them. The sequence stops by itself as soon as the customer answers. Start it from the chat panel.') }}</p>
  @include('layouts.partials.messages')
  <p class="small"><span class="badge bg-success">{{ $replied }}</span> {{ __('stopped because the customer replied') }} · <span class="badge bg-secondary">{{ $done }}</span> {{ __('completed') }}</p>

  @foreach ($sequences->concat([new \App\Models\Sequence()]) as $seq)
    @php $isNew = !$seq->exists; $steps = $isNew ? collect([['delay_minutes' => 1440, 'body' => '']]) : $seq->steps->map(fn ($s) => ['delay_minutes' => $s->delay_minutes, 'body' => $s->body]); @endphp
    <form method="POST" action="{{ $isNew ? route('sequences.store') : route('sequences.update', $seq->id) }}" class="card p-3 mb-3 seq-form">
      @csrf @if (!$isNew) @method('PUT') @endif
      <div class="d-flex gap-2 align-items-center mb-2">
        <input name="name" class="form-control" maxlength="120" required placeholder="{{ $isNew ? __('New sequence name') : '' }}" value="{{ $isNew ? '' : $seq->name }}">
        <div class="form-check form-switch text-nowrap"><input class="form-check-input" type="checkbox" name="active" value="1" @checked($isNew || $seq->active)><label class="form-check-label small">{{ __('Active') }}</label></div>
      </div>
      <div class="seq-steps">
        @foreach ($steps as $i => $s)
          <div class="row g-2 mb-2 seq-step">
            <div class="col-md-3"><div class="input-group input-group-sm"><span class="input-group-text">{{ __('Wait') }}</span><input type="number" min="1" name="steps[{{ $i }}][delay_minutes]" class="form-control" value="{{ $s['delay_minutes'] }}"><span class="input-group-text">min</span></div></div>
            <div class="col-md-9"><textarea name="steps[{{ $i }}][body]" rows="2" maxlength="4096" class="form-control form-control-sm" placeholder="{{ __('Message of this step') }}">{{ $s['body'] }}</textarea></div>
          </div>
        @endforeach
      </div>
      <p class="small text-muted mb-2">{{ __('Variables') }}: <code>{{ $o }}name{{ $c }}</code> <code>{{ $o }}first_name{{ $c }}</code> · 1440 min = 1 {{ __('day') }}</p>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-sm btn-outline-secondary seq-add">+ {{ __('Add step') }}</button>
        <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
        @if (!$isNew)<button class="btn btn-sm btn-outline-danger ms-auto" form="del{{ $seq->id }}" onclick="return confirm('{{ __('Remove this sequence?') }}')">{{ __('Remove') }}</button><span class="small text-muted align-self-center">{{ $seq->running }} {{ __('running') }}</span>@endif
      </div>
    </form>
    @if (!$isNew)<form id="del{{ $seq->id }}" method="POST" action="{{ route('sequences.destroy', $seq->id) }}">@csrf @method('DELETE')</form>@endif
  @endforeach
</div>
<script>
document.querySelectorAll('.seq-add').forEach(function (b) {
  b.addEventListener('click', function () {
    var box = b.closest('form').querySelector('.seq-steps');
    var n = box.querySelectorAll('.seq-step').length;
    if (n >= {{ \App\Services\Chat\Sequences::MAX_STEPS }}) return;
    var last = box.querySelector('.seq-step:last-child').cloneNode(true);
    last.querySelectorAll('input, textarea').forEach(function (el) { el.name = el.name.replace(/steps\[\d+\]/, 'steps[' + n + ']'); if (el.tagName === 'TEXTAREA') el.value = ''; });
    box.appendChild(last);
  });
});
</script>
@endsection
