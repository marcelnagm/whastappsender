@extends('layouts.app-master')

@section('template_title', __('Business knowledge'))

@section('content')
@php $ask = session('ask'); $badge = ['ready' => 'success', 'pending' => 'secondary', 'indexing' => 'info', 'error' => 'danger']; @endphp
<div class="container py-4" style="max-width:980px">
  <a href="{{ route('ai.index') }}" class="small">&larr; {{ __('AI agents') }}</a>
  <h1 class="h3 fw-bold mt-1"><i class="bi bi-mortarboard text-primary me-2"></i>{{ __('Business knowledge') }}</h1>
  <p class="text-muted">{{ __('Teach the AI about your business with FAQs, texts, files and your knowledge base. Agents with "Answer using what I taught the AI" look this up before answering and say they will check when nothing matches.') }}</p>
  @include('layouts.partials.messages')

  <div class="card shadow-sm mb-4"><div class="card-body d-flex flex-wrap justify-content-between gap-2 align-items-center">
    <div>
      <div class="fw-semibold">{{ $embedder->kind() === 'hosted' ? __('Search model: :m', ['m' => $embedder->id()]) : __('Basic search (built in)') }}</div>
      <div class="small text-muted">
        {{ $embedder->kind() === 'hosted' ? __('Understands meaning and synonyms.') : __('Matches the words of the question. For smarter search add an OpenAI or Mistral key to one of your agents.') }}
        · {{ $chunks }} / {{ $max }} {{ __('passages') }}@if ($allChunks > $chunks) · <span class="text-warning">{{ $allChunks - $chunks }} {{ __('passages need re-indexing') }}</span>@endif
      </div>
    </div>
    <form method="POST" action="{{ route('ai.knowledge.reindex-all') }}">@csrf<button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> {{ __('Re-index all') }}</button></form>
  </div></div>

  <div class="row g-3 mb-4">
    <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-header fw-semibold">{{ __('Add a FAQ') }}</div><div class="card-body">
      <form method="POST" action="{{ route('ai.knowledge.faq') }}">@csrf
        <input type="hidden" name="dismiss" value="{{ request('d') }}">
        <input name="question" maxlength="300" required class="form-control mb-2" placeholder="{{ __('Question') }}" value="{{ old('question', $prefill) }}">
        <textarea name="answer" rows="3" maxlength="8000" required class="form-control mb-2" placeholder="{{ __('Answer') }}">{{ old('answer') }}</textarea>
        <button class="btn btn-primary btn-sm">{{ __('Add FAQ') }}</button>
      </form></div></div></div>
    <div class="col-lg-6"><div class="card shadow-sm h-100"><div class="card-header fw-semibold">{{ __('Add a file or text') }}</div><div class="card-body">
      <form method="POST" action="{{ route('ai.knowledge.file') }}" enctype="multipart/form-data" class="mb-3">@csrf
        <input type="file" name="file" required class="form-control form-control-sm mb-1" accept=".{{ implode(',.', $extensions) }}">
        <div class="form-text mb-2">{{ strtoupper(implode(', ', $extensions)) }} · {{ round(config('rag.max_upload_kb') / 1024) }} MB</div>
        <button class="btn btn-outline-primary btn-sm">{{ __('Upload file') }}</button>
      </form>
      <form method="POST" action="{{ route('ai.knowledge.text') }}">@csrf
        <input name="title" maxlength="200" required class="form-control form-control-sm mb-2" placeholder="{{ __('Title (e.g. Return policy)') }}">
        <textarea name="content" rows="3" required class="form-control form-control-sm mb-2" placeholder="{{ __('Paste a text') }}"></textarea>
        <button class="btn btn-outline-primary btn-sm">{{ __('Add text') }}</button>
      </form></div></div></div>
  </div>

  <div class="card shadow-sm mb-4"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><div class="fw-semibold">{{ __('Knowledge base') }}</div><div class="small text-muted">{{ __(':n active article(s) in your knowledge base can also be learned by the AI and follow future edits.', ['n' => $kbCount]) }}</div></div>
    <form method="POST" action="{{ route('ai.knowledge.import-kb') }}">@csrf<button class="btn btn-sm btn-outline-primary" @disabled($kbCount === 0)><i class="bi bi-book"></i> {{ __('Import knowledge base') }}</button></form>
  </div></div>

  <div class="card shadow-sm mb-4"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
      <div><div class="fw-semibold">{{ __('Products and prices') }}</div><div class="small text-muted">{{ __(':n active product(s) can be learned by the AI (names, SKU, prices) and follow future edits.', ['n' => $productCount]) }}</div></div>
      <form method="POST" action="{{ route('ai.knowledge.import-products') }}">@csrf<button class="btn btn-sm btn-outline-primary" @disabled($productCount === 0)><i class="bi bi-box-seam"></i> {{ __('Import products') }}</button></form>
    </div>
    <div class="fw-semibold">{{ __('Your website') }}</div>
    <div class="small text-muted mb-2">{{ __('The AI reads the public pages of your site (up to :n pages, obeying robots.txt) and learns from them. Re-index to read it again; it is also refreshed every week.', ['n' => config('rag.crawl_max_pages')]) }}</div>
    <form method="POST" action="{{ route('ai.knowledge.site') }}" class="d-flex gap-2">@csrf
      <input name="url" type="url" maxlength="255" required class="form-control form-control-sm" placeholder="https://www.yourcompany.com" value="{{ old('url') }}">
      <button class="btn btn-sm btn-primary text-nowrap">{{ __('Read website') }}</button>
    </form>
    @error('url')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
  </div></div>

  <div class="card shadow-sm mb-4"><div class="card-header fw-semibold">{{ __('Test a question') }}</div><div class="card-body">
    <form method="POST" action="{{ route('ai.knowledge.ask') }}" class="d-flex gap-2">@csrf
      <input name="question" maxlength="500" required class="form-control" placeholder="{{ __('What would a customer ask?') }}" value="{{ old('question') }}">
      <button class="btn btn-primary">{{ __('Search') }}</button>
    </form>
    @error('question')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
    @if ($ask)
      <div class="mt-3">
        @forelse ($ask['hits'] as $i => $h)
          <div class="border rounded p-2 mb-2"><div class="small text-muted mb-1">[{{ $i + 1 }}] {{ $h['source'] }} · {{ __('score') }} {{ $h['score'] }}</div><div style="white-space:pre-wrap">{{ \Illuminate\Support\Str::limit($h['content'], 600) }}</div></div>
        @empty
          <div class="alert alert-warning mb-0">{{ __('Nothing above the minimum score (:min). The agent would say it will check with the team.', ['min' => $ask['min_score']]) }}@if ($ask['best'] !== null) {{ __('Best score') }}: {{ $ask['best'] }}@endif</div>
        @endforelse
      </div>
    @endif
  </div></div>

  @if ($questions->isNotEmpty())
  <div class="card shadow-sm mb-4"><div class="card-header fw-semibold">{{ __('Questions the AI could not answer') }}</div>
    <ul class="list-group list-group-flush">
      @foreach ($questions as $q)
        <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
          <span>{{ $q->question }} <span class="badge text-bg-light border">{{ $q->hits }}×</span></span>
          <span class="text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="{{ route('ai.knowledge.index', ['q' => $q->question, 'd' => $q->id]) }}#faq">{{ __('Create FAQ') }}</a>
            <form method="POST" action="{{ route('ai.knowledge.question.dismiss', $q->id) }}" class="d-inline">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-secondary" title="{{ __('Dismiss') }}">&times;</button></form>
          </span>
        </li>
      @endforeach
    </ul></div>
  @endif

  <div class="card shadow-sm"><div class="card-header fw-semibold">{{ __('Sources') }} ({{ $sources->count() }})</div>
    <div class="table-responsive"><table class="table align-middle mb-0"><tbody>
      @forelse ($sources as $s)
        <tr><td><span class="badge text-bg-light border me-1">{{ strtoupper($s->type) }}</span>{{ $s->title }}@if ($s->status === 'error')<div class="small text-danger">{{ $s->error }}</div>@endif</td>
          <td><span class="badge text-bg-{{ $badge[$s->status] ?? 'secondary' }}">{{ __($s->status) }}</span></td>
          <td class="small text-muted">{{ $s->chunks_count }} {{ __('passages') }}</td>
          <td class="text-end text-nowrap">
            <form method="POST" action="{{ route('ai.knowledge.reindex', $s->id) }}" class="d-inline">@csrf<button class="btn btn-sm btn-outline-secondary" title="{{ __('Re-index') }}"><i class="bi bi-arrow-repeat"></i></button></form>
            <form method="POST" action="{{ route('ai.knowledge.destroy', $s->id) }}" class="d-inline" onsubmit="return confirm(@json(__('Delete this source?')))">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
          </td></tr>
      @empty
        <tr><td class="text-muted p-3">{{ __('Nothing taught yet. Add a FAQ, a file or a text above.') }}</td></tr>
      @endforelse
    </tbody></table></div></div>
</div>
@endsection
