@extends('layouts.app-master')

@section('template_title', __('Knowledge base'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-book me-1"></i> {{ __('Knowledge base') }}</h4>
  <p class="text-muted small">{{ __('Answers your team reuses. In the chat, type ? followed by a word (for example ?delivery) to find and insert one.') }}</p>
  @include('layouts.partials.messages')
  <form method="GET" class="mb-3"><input name="q" value="{{ $q }}" class="form-control" placeholder="{{ __('Search') }}"></form>
  @foreach ($articles->concat([new \App\Models\KbArticle(['active' => true])]) as $a)
    @php $new = !$a->exists; @endphp
    <form method="POST" action="{{ $new ? route('kb.store') : route('kb.update', $a->id) }}" class="card p-3 mb-2">
      @csrf @if (!$new) @method('PUT') @endif
      <div class="row g-2 mb-2">
        <div class="col-md-6"><input name="title" class="form-control" required maxlength="160" placeholder="{{ $new ? __('New article title') : '' }}" value="{{ $a->title }}"></div>
        <div class="col-md-4"><input name="keywords" class="form-control" maxlength="255" placeholder="{{ __('Extra keywords') }}" value="{{ $a->keywords }}"></div>
        <div class="col-md-2"><div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="active" value="1" @checked($new || $a->active)><label class="form-check-label small">{{ __('Active') }}</label></div></div>
      </div>
      <textarea name="body" rows="3" class="form-control mb-2" required maxlength="4000" placeholder="{{ __('Text sent to the customer') }}">{{ $a->body }}</textarea>
      <div class="d-flex gap-2">
        <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
        @if (!$new)<span class="small text-muted align-self-center">{{ $a->uses }} {{ __('uses') }}</span>
        <button class="btn btn-sm btn-outline-danger ms-auto" form="kbdel{{ $a->id }}" onclick="return confirm('{{ __('Remove this article?') }}')">{{ __('Remove') }}</button>@endif
      </div>
    </form>
    @if (!$new)<form id="kbdel{{ $a->id }}" method="POST" action="{{ route('kb.destroy', $a->id) }}">@csrf @method('DELETE')</form>@endif
  @endforeach
  {{ $articles->links() }}
</div>
@endsection
