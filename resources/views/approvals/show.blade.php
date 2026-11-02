@extends('layouts.app-master')

@section('template_title', __('Review message'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-8">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-check2-square me-2"></i>{{ $item->name }}</h1>
    @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

    <div class="card shadow-sm mb-4"><div class="card-body">
      <div class="small text-muted mb-1">{{ __('Message') }}</div>
      <div class="border rounded p-3 bg-light" style="white-space:pre-wrap">{{ $item->text }}</div>
      @if ($item->image)<div class="small mt-2"><a href="{{ $item->image }}" target="_blank" rel="noopener">{{ __('Attached media') }}</a></div>@endif
      @if ($item->approval_note)<div class="alert alert-secondary border-0 mt-3 mb-0 small">{{ $item->approval_note }}</div>@endif
    </div></div>

    <form method="POST" id="audience-form" class="card shadow-sm">
      @csrf
      <div class="card-body">
        <h2 class="h5">{{ __('Audience') }}</h2>

        <div class="mb-3">
          <div class="small fw-bold mb-1">{{ __('Channels') }}</div>
          @foreach ($channels as $ch)
            <div class="form-check form-check-inline">
              <input class="form-check-input aud" type="checkbox" name="channels[]" value="{{ $ch }}" id="ch-{{ $ch }}" @checked(in_array($ch, $audience['channels'], true))>
              <label class="form-check-label" for="ch-{{ $ch }}">{{ __(ucfirst($ch)) }}</label>
            </div>
          @endforeach
          <div class="form-text">{{ __('Telegram, Instagram, Facebook and TikTok only reach people who wrote to you and are still inside the reply window.') }}</div>
        </div>

        <div class="mb-3">
          <div class="small fw-bold mb-1">{{ __('Contact ranking (score)') }}</div>
          @foreach ($tiers as $t)
            <div class="form-check form-check-inline">
              <input class="form-check-input aud" type="checkbox" name="tiers[]" value="{{ $t }}" id="t-{{ $t }}" @checked(in_array($t, $audience['tiers'], true))>
              <label class="form-check-label" for="t-{{ $t }}">{{ __(['hot' => 'Hot', 'warm' => 'Warm', 'cold' => 'Cold', 'frozen' => 'Frozen'][$t]) }}</label>
            </div>
          @endforeach
          <div class="form-text">{{ __('Leave all unchecked to ignore the ranking.') }}</div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3"><label class="form-label small">{{ __('Minimum score') }}</label><input type="number" min="0" max="100" name="min_score" class="form-control aud" value="{{ $audience['min_score'] }}"></div>
          <div class="col-6 col-md-3"><label class="form-label small">{{ __('Maximum score') }}</label><input type="number" min="0" max="100" name="max_score" class="form-control aud" value="{{ $audience['max_score'] }}"></div>
        </div>

        <div class="alert alert-info border-0 mb-3"><strong id="aud-total">{{ $count['total'] }}</strong> {{ __('contact(s) will receive this message') }}
          <span class="small text-muted" id="aud-by">@foreach ($count['by_channel'] as $c => $n) {{ __(ucfirst($c)) }}: {{ $n }} @endforeach</span></div>

        <div class="mb-3"><label class="form-label small">{{ __('Note (optional)') }}</label><textarea name="note" rows="2" class="form-control" maxlength="1000">{{ old('note') }}</textarea></div>

        <div class="d-flex flex-wrap gap-2">
          <button formaction="{{ route('approvals.approve', $item->id) }}" class="btn btn-success">{{ __('Approve') }}</button>
          @if ($canLaunch)
            <button formaction="{{ route('approvals.approve', $item->id) }}" name="launch" value="1" class="btn btn-primary">{{ __('Approve and send') }}</button>
          @endif
          <button formaction="{{ route('approvals.reject', $item->id) }}" class="btn btn-outline-danger">{{ __('Reject') }}</button>
          <a href="{{ route('approvals.index') }}" class="btn btn-link">{{ __('Back') }}</a>
        </div>
      </div>
    </form>
  </div></div>
</div>
<script>
(function () {
  var f = document.getElementById('audience-form'), t;
  function refresh() {
    fetch('{{ route('approvals.preview', $item->id) }}', {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}, body: new FormData(f)})
      .then(function (r) { return r.json(); }).then(function (d) {
        document.getElementById('aud-total').textContent = d.total;
        document.getElementById('aud-by').textContent = Object.keys(d.by_channel).map(function (k) { return k + ': ' + d.by_channel[k]; }).join(' · ');
      });
  }
  f.querySelectorAll('.aud').forEach(function (el) { el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 300); }); });
})();
</script>
@endsection
