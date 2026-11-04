@extends('layouts.app-master')

@section('template_title', __('Pipeline'))

@section('content')
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); @endphp
<style>
  .kb-board{display:flex;gap:12px;overflow-x:auto;align-items:flex-start;padding-bottom:12px}
  .kb-col{min-width:260px;width:260px;background:#f4f5f7;border-radius:10px;flex:0 0 auto}
  .kb-head{padding:10px 12px;border-top:4px solid var(--c,#6c757d);border-radius:10px 10px 0 0}
  .kb-list{padding:8px;min-height:60px}
  .kb-card{background:#fff;border-radius:8px;padding:10px;margin-bottom:8px;box-shadow:0 1px 2px rgba(0,0,0,.08);cursor:grab}
  .kb-card.dragging{opacity:.4}
  .kb-list.over{background:#e7f1ff;border-radius:8px}
</style>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-kanban me-2"></i>{{ __('Sales pipeline') }}</h1>
    <div class="d-flex flex-wrap gap-2 align-items-center">
      <select class="form-select form-select-sm w-auto" onchange="location='{{ route('pipeline.index') }}?pipeline='+this.value">
        @foreach ($pipelines as $p)<option value="{{ $p->id }}" @selected($p->id === $pipeline->id)>{{ $p->name }}</option>@endforeach
      </select>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dealModal" onclick="newDeal()"><i class="bi bi-plus-lg me-1"></i>{{ __('New deal') }}</button>
      <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#setupModal"><i class="bi bi-gear me-1"></i>{{ __('Customize') }}</button>
    </div>
  </div>

  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <div class="row g-2 mb-3" id="summary">
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('In progress') }}</div><strong data-s="open">{{ $summary['open'] }}</strong> · <span data-s="open_value">{{ $money($summary['open_value']) }}</span></div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Won') }}</div><strong data-s="won">{{ $summary['won'] }}</strong> · <span data-s="won_value">{{ $money($summary['won_value']) }}</span></div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Lost') }}</div><strong data-s="lost">{{ $summary['lost'] }}</strong></div></div></div>
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Win rate') }}</div><strong data-s="win_rate">{{ $summary['win_rate'] === null ? '—' : $summary['win_rate'].'%' }}</strong></div></div></div>
  </div>

  <div class="kb-board">
    @foreach ($stages as $s)
      <div class="kb-col" style="--c:{{ $s->color ?: '#6c757d' }}">
        <div class="kb-head d-flex justify-content-between">
          <strong>{{ $s->name }}</strong>
          <span class="small text-muted">{{ $s->deals->count() }} · {{ $money($s->deals->sum('value')) }}</span>
        </div>
        <div class="kb-list" data-stage="{{ $s->id }}" data-kind="{{ $s->kind }}">
          @foreach ($s->deals as $d)
            <div class="kb-card" draggable="true" data-deal="{{ $d->id }}">
              <div class="d-flex justify-content-between gap-2">
                <strong class="text-break">{{ $d->title }}</strong>
                <a href="#" class="small text-decoration-none" data-bs-toggle="modal" data-bs-target="#dealModal"
                   data-edit="{{ json_encode(['id' => $d->id, 'title' => $d->title, 'value' => $d->value, 'contact_id' => $d->contact_id, 'date' => optional($d->expected_close_date)->format('Y-m-d')]) }}"
                   onclick="editDeal(JSON.parse(this.dataset.edit))"><i class="bi bi-pencil"></i></a>
              </div>
              <div class="small">{{ $money($d->value) }}</div>
              @if ($d->contact)<div class="small text-muted"><i class="bi bi-person"></i> {{ $d->contact->name }}</div>@endif
              @if ($d->expected_close_date)<div class="small text-muted"><i class="bi bi-calendar-event"></i> {{ $d->expected_close_date->format('d/m/Y') }}</div>@endif
              @if ($d->status === 'lost' && $d->lost_reason)<div class="small text-danger">{{ $d->lost_reason }}</div>@endif
            </div>
          @endforeach
        </div>
      </div>
    @endforeach
  </div>
</div>

{{-- deal --}}
<div class="modal fade" id="dealModal" tabindex="-1"><div class="modal-dialog"><form class="modal-content" method="post" id="dealForm" action="{{ route('pipeline.deals.store', $pipeline->id) }}">
  @csrf <input type="hidden" name="_method" value="POST" id="dealMethod">
  <div class="modal-header"><h5 class="modal-title" id="dealTitle">{{ __('New deal') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div><label class="form-label">{{ __('Title') }}</label><input name="title" id="dTitle" class="form-control" required maxlength="160"></div>
    <div class="row g-2">
      <div class="col-6"><label class="form-label">{{ __('Value') }}</label><input name="value" id="dValue" type="number" step="0.01" min="0" class="form-control"></div>
      <div class="col-6"><label class="form-label">{{ __('Expected close') }}</label><input name="expected_close_date" id="dDate" type="date" class="form-control"></div>
    </div>
    <div><label class="form-label">{{ __('Contact') }}</label>
      <select name="contact_id" id="dContact" class="form-select"><option value="">—</option>
        @foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->contact }})</option>@endforeach
      </select></div>
  </div>
  <div class="modal-footer justify-content-between">
    <button type="submit" form="dealDelete" class="btn btn-outline-danger btn-sm d-none" id="dealDeleteBtn" onclick="return confirm('{{ __('Remove this deal?') }}')">{{ __('Delete') }}</button>
    <button class="btn btn-primary">{{ __('Save') }}</button>
  </div>
</form>
<form id="dealDelete" method="post">@csrf @method('DELETE')</form></div></div>

{{-- customize --}}
<div class="modal fade" id="setupModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title">{{ __('Customize') }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <div class="modal-body vstack gap-3">
    <div class="small text-muted">{{ __('Won and Lost stages close the deal when a card is dropped on them; the other stages keep it open.') }}</div>
    @foreach ($stages as $s)
      <form method="post" action="{{ route('pipeline.stages.update', $s->id) }}" class="d-flex gap-2 align-items-center">
        @csrf @method('PUT')
        <input name="name" value="{{ $s->name }}" class="form-control form-control-sm" required maxlength="80">
        <select name="kind" class="form-select form-select-sm w-auto"><option value="open" @selected($s->kind==='open')>{{ __('Open') }}</option><option value="won" @selected($s->kind==='won')>{{ __('Won') }}</option><option value="lost" @selected($s->kind==='lost')>{{ __('Lost') }}</option></select>
        <input type="color" name="color" value="{{ $s->color ?: '#6c757d' }}" class="form-control form-control-color form-control-sm">
        <button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button>
        <button class="btn btn-sm btn-outline-danger" form="delStage{{ $s->id }}" onclick="return confirm('{{ __('Remove this stage?') }}')"><i class="bi bi-trash"></i></button>
      </form>
      <form id="delStage{{ $s->id }}" method="post" action="{{ route('pipeline.stages.destroy', $s->id) }}">@csrf @method('DELETE')</form>
    @endforeach
    <form method="post" action="{{ route('pipeline.stages.store', $pipeline->id) }}" class="d-flex gap-2 border-top pt-3">
      @csrf
      <input name="name" class="form-control form-control-sm" placeholder="{{ __('New stage') }}" required maxlength="80">
      <select name="kind" class="form-select form-select-sm w-auto"><option value="open">{{ __('Open') }}</option><option value="won">{{ __('Won') }}</option><option value="lost">{{ __('Lost') }}</option></select>
      <button class="btn btn-sm btn-primary">{{ __('Add stage') }}</button>
    </form>
    <form method="post" action="{{ route('pipeline.pipelines.store') }}" class="d-flex gap-2 border-top pt-3">
      @csrf
      <input name="name" class="form-control form-control-sm" placeholder="{{ __('New pipeline') }}" required maxlength="120">
      <button class="btn btn-sm btn-outline-primary">{{ __('Create pipeline') }}</button>
    </form>
    <form method="post" action="{{ route('pipeline.pipelines.destroy', $pipeline->id) }}" onsubmit="return confirm('{{ __('Remove this pipeline and all its deals?') }}')">
      @csrf @method('DELETE')
      <button class="btn btn-sm btn-outline-danger">{{ __('Remove this pipeline') }}</button>
    </form>
  </div>
</div></div></div>
@endsection

@section('js')
<script>
const money = v => Number(v).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const dealStore = @json(route('pipeline.deals.store', $pipeline->id));
const dealBase = @json(url('pipeline/deals'));
function setDeal(url, method, title, d) {
  const f = document.getElementById('dealForm'); f.action = url;
  document.getElementById('dealMethod').value = method;
  document.getElementById('dealTitle').textContent = title;
  document.getElementById('dTitle').value = d.title || ''; document.getElementById('dValue').value = d.value || '';
  document.getElementById('dDate').value = d.date || ''; document.getElementById('dContact').value = d.contact_id || '';
  const del = document.getElementById('dealDeleteBtn');
  if (d.id) { del.classList.remove('d-none'); document.getElementById('dealDelete').action = dealBase + '/' + d.id; } else del.classList.add('d-none');
}
function newDeal() { setDeal(dealStore, 'POST', @json(__('New deal')), {}); }
function editDeal(d) { setDeal(dealBase + '/' + d.id, 'PUT', @json(__('Edit deal')), d); }

let dragged = null;
document.querySelectorAll('.kb-card').forEach(c => {
  c.addEventListener('dragstart', () => { dragged = c; c.classList.add('dragging'); });
  c.addEventListener('dragend', () => { c.classList.remove('dragging'); document.querySelectorAll('.kb-list').forEach(l => l.classList.remove('over')); });
});
document.querySelectorAll('.kb-list').forEach(list => {
  list.addEventListener('dragover', e => { e.preventDefault(); list.classList.add('over'); });
  list.addEventListener('dragleave', () => list.classList.remove('over'));
  list.addEventListener('drop', async e => {
    e.preventDefault(); list.classList.remove('over');
    if (!dragged) return;
    const after = [...list.querySelectorAll('.kb-card:not(.dragging)')].find(c => e.clientY < c.getBoundingClientRect().top + c.offsetHeight / 2);
    const card = dragged; const origin = card.parentNode; const next = card.nextSibling;
    after ? list.insertBefore(card, after) : list.appendChild(card);
    const position = [...list.querySelectorAll('.kb-card')].indexOf(card);
    let reason = null;
    if (list.dataset.kind === 'lost') { reason = prompt(@json(__('Why was it lost? (optional)'))); if (reason === null) reason = ''; }
    try {
      const r = await fetch(dealBase + '/' + card.dataset.deal + '/move', {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())},
        body: JSON.stringify({stage_id: list.dataset.stage, position, lost_reason: reason})});
      if (!r.ok) throw new Error();
      location.reload();
    } catch (err) { origin.insertBefore(card, next); alert(@json(__('Could not move the deal.'))); }
  });
});
</script>
@endsection
