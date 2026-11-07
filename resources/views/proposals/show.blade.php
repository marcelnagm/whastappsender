@extends('layouts.app-master')

@section('template_title', __('Proposal') . ' #' . $p->number)

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $cur = config('crm.currency', 'R$'); $locked = $p->isAnswered(); @endphp
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 fw-bold mb-0"><i class="bi bi-file-earmark-text me-2"></i>{{ __('Proposal') }} #{{ $p->number }}
      <span class="badge bg-secondary">{{ $p->isExpired() ? __('Expired') : __(ucfirst($p->status)) }}</span></h1>
    <a href="{{ route('proposals.index') }}" class="btn btn-outline-secondary btn-sm">{{ __('Proposals') }}</a>
  </div>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <label class="form-label small mb-1">{{ __('Link for the customer') }}</label>
    <div class="input-group mb-2">
      <input class="form-control" id="plink" readonly value="{{ $p->url() }}">
      <button class="btn btn-outline-primary" type="button" id="pcopy"><i class="bi bi-clipboard"></i> {{ __('Copy') }}</button>
      @if ($deal && $deal->contact)
        <a class="btn btn-outline-success" id="pwa" target="_blank" rel="noopener"
           href="https://wa.me/{{ preg_replace('/\D/', '', $deal->contact->contact) }}?text={{ rawurlencode(__('Hello! Here is our proposal: :url', ['url' => $p->url()])) }}"><i class="bi bi-whatsapp"></i> WhatsApp</a>
      @endif
      <a class="btn btn-outline-secondary" target="_blank" href="{{ $p->url() }}?preview=1"><i class="bi bi-eye"></i> {{ __('Preview') }}</a>
    </div>
    <div class="small text-muted">
      @if ($p->sent_at) {{ __('Sent') }}: {{ $p->sent_at->format('d/m/Y H:i') }} · @endif
      @if ($p->viewed_at) {{ __('Opened') }}: {{ $p->viewed_at->format('d/m/Y H:i') }} · @endif
      @if ($p->answered_at) {{ __('Answered') }}: {{ $p->answered_at->format('d/m/Y H:i') }}@if ($p->answered_by) {{ __('by') }} {{ $p->answered_by }}@endif @endif
      @if ($p->decline_reason) · {{ __('Reason') }}: {{ $p->decline_reason }} @endif
    </div>
  </div></div>

  <div class="row g-3">
    <div class="col-lg-7">
      <form method="POST" action="{{ route('proposals.update', $p->id) }}" class="card border-0 shadow-sm"><div class="card-body vstack gap-2">@csrf @method('PUT')
        <div><label class="form-label small mb-1">{{ __('Title') }}</label><input name="title" class="form-control" value="{{ old('title', $p->title) }}" required maxlength="160" @disabled($locked)></div>
        <div><label class="form-label small mb-1">{{ __('Introduction') }}</label><textarea name="intro" rows="3" class="form-control" maxlength="3000" @disabled($locked)>{{ old('intro', $p->intro) }}</textarea></div>
        <div><label class="form-label small mb-1">{{ __('Terms and conditions') }}</label><textarea name="terms" rows="4" class="form-control" maxlength="5000" @disabled($locked)>{{ old('terms', $p->terms) }}</textarea></div>
        <div><label class="form-label small mb-1">{{ __('Valid until') }}</label><input type="date" name="valid_until" class="form-control" value="{{ old('valid_until', optional($p->valid_until)->format('Y-m-d')) }}" @disabled($locked)></div>
        @unless ($locked)<div><button class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>@endunless
      </div></form>
    </div>
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6 class="fw-bold">{{ __('Items') }}</h6>
        @foreach ($p->items as $i)
          <div class="d-flex justify-content-between small border-bottom py-1"><span>{{ rtrim(rtrim(number_format($i['quantity'], 2, ',', ''), '0'), ',') }} × {{ $i['name'] }}</span><span>{{ $money($i['total']) }}</span></div>
        @endforeach
        <div class="d-flex justify-content-between fw-bold pt-2"><span>{{ __('Total') }}</span><span>{{ $cur }} {{ $money($p->total) }}</span></div>
        @unless ($locked)
          <form method="POST" action="{{ route('proposals.refresh', $p->id) }}" class="mt-2">@csrf<button class="btn btn-sm btn-outline-secondary">{{ __('Update from the deal') }}</button></form>
        @endunless
      </div></div>
      <form method="POST" action="{{ route('proposals.destroy', $p->id) }}" onsubmit="return confirm('{{ __('Remove this proposal?') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button></form>
    </div>
  </div>
</div>
<script>
(function () {
  var tok = @json(csrf_token()), sentUrl = @json(route('proposals.sent', $p->id));
  function sent() { fetch(sentUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': tok, 'Accept': 'application/json' } }); }
  document.getElementById('pcopy').addEventListener('click', function () { var i = document.getElementById('plink'); i.select(); try { navigator.clipboard.writeText(i.value); } catch (e) { document.execCommand('copy'); } sent(); });
  var wa = document.getElementById('pwa'); if (wa) wa.addEventListener('click', sent);
})();
</script>
@endsection
