@extends('layouts.app-master')

@section('template_title', __('Signup welcome'))

@section('content')
<div class="container py-4" style="max-width: 860px;">
    @include('layouts.partials.messages')
    <h2 class="fw-bold mb-3"><i class="bi bi-envelope-heart text-primary me-2"></i>{{ __('Signup welcome') }}</h2>
    <p class="small text-muted">{{ __('The message below is sent on WhatsApp to everyone who registers on the site.') }}</p>

    @if ($envFallback)
        <div class="alert alert-warning border-0 small">{{ __('Using the value of WHATSAPP_MESSAGE_UP from .env. Save a choice here to manage it from the panel.') }}</div>
    @endif
    @error('item_id')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror

    <form method="POST" action="{{ route('admin.welcome.update') }}" class="card shadow-sm">
        @csrf
        <div class="card-body">
            <label class="form-label fw-bold" for="item_id">{{ __('Message') }}</label>
            <select name="item_id" id="item_id" class="form-select mb-3">
                <option value="">{{ __('None (do not send a welcome message)') }}</option>
                @foreach ($items as $item)
                    <option value="{{ $item->id }}" data-text="{{ $item->text }}" @selected((int) old('item_id', $currentId) === (int) $item->id)>
                        #{{ $item->id }} · {{ $item->name }}{{ $item->user ? ' — ' . $item->user->name : '' }}
                    </option>
                @endforeach
            </select>

            <div class="small text-muted mb-1">{{ __('Preview') }}</div>
            <div id="preview" class="border rounded p-3 bg-light" style="white-space:pre-wrap;min-height:60px">{{ $current?->text }}</div>
            @if ($current && $current->image)<div class="small mt-2"><a href="{{ $current->image }}" target="_blank" rel="noopener">{{ __('Attached media') }}</a></div>@endif
        </div>
        <div class="card-footer bg-white"><button class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>
</div>
<script>
document.getElementById('item_id').addEventListener('change', function () {
  var o = this.options[this.selectedIndex];
  document.getElementById('preview').textContent = o.dataset.text || '';
});
</script>
@endsection
