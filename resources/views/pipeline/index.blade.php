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
      <a href="{{ route('pipeline.sources') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-signpost-split me-1"></i>{{ __('Lead sources') }}</a>
      <a href="{{ route('proposals.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-text me-1"></i>{{ __('Proposals') }}</a>
      <a href="{{ route('pipeline.products.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-box-seam me-1"></i>{{ __('Products') }}</a>
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
    <div class="col-6 col-md-3"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">{{ __('Forecast') }} <a href="{{ route('pipeline.priorities') }}" class="text-decoration-none">· {{ __('Priorities') }}</a></div><strong>{{ $money($weighted) }}</strong></div></div></div>
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
                   data-edit="{{ json_encode(['id' => $d->id, 'title' => $d->title, 'value' => $d->value, 'contact_id' => $d->contact_id, 'origin' => $d->origin, 'date' => optional($d->expected_close_date)->format('Y-m-d')]) }}"
                   onclick="editDeal(JSON.parse(this.dataset.edit))"><i class="bi bi-pencil"></i></a>
              </div>
              <div class="small">{{ $money($d->value) }}
                @php $f = $scores[$d->id] ?? null; @endphp
                @if ($f && $f['score'] !== null)
                  <span class="badge float-end bg-{{ ['hot' => 'success', 'warm' => 'warning', 'cold' => 'secondary'][$f['level']] }}" title="{{ collect($f['reasons'])->map(fn ($r) => ($r['delta'] > 0 ? '+' : '') . $r['delta'] . ' ' . $r['text'])->implode(' · ') }}">{{ $f['score'] }}%</span>
                @endif
              </div>
              @if ($d->origin)<div class="small text-muted"><i class="bi bi-signpost-split"></i> {{ $d->origin }}</div>@endif
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
    <div class="d-none" id="itemsBox">
      <label class="form-label mb-1">{{ __('Items') }}</label>
      <div id="itemsList" class="small mb-2"></div>
      <div class="row g-1">
        <div class="col-12"><select id="iProduct" class="form-select form-select-sm"></select></div>
        <div class="col-5"><input id="iName" class="form-control form-control-sm" placeholder="{{ __('Or type a name') }}" maxlength="120"></div>
        <div class="col-3"><input id="iPrice" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="{{ __('Price') }}"></div>
        <div class="col-2"><input id="iQty" type="number" step="any" min="0.01" value="1" class="form-control form-control-sm" title="{{ __('Quantity') }}"></div>
        <div class="col-2"><input id="iDisc" type="number" step="any" min="0" max="100" value="0" class="form-control form-control-sm" title="{{ __('Discount %') }}"></div>
        <div class="col-12"><button type="button" class="btn btn-sm btn-outline-primary" id="iAdd"><i class="bi bi-plus"></i> {{ __('Add item') }}</button>
          <a href="{{ route('pipeline.products.index') }}" class="small ms-2">{{ __('Products') }}</a></div>
      </div>
    </div>
    <div><label class="form-label">{{ __('Origin') }}</label><input name="origin" id="dOrigin" class="form-control" maxlength="80" list="originList" placeholder="{{ __('Where the lead came from') }}">
      <datalist id="originList">@foreach ($origins as $o)<option value="{{ $o }}">@endforeach</datalist></div>
    <div><label class="form-label">{{ __('Contact') }}</label>
      <select name="contact_id" id="dContact" class="form-select"><option value="">—</option>
        @foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }} ({{ $c->contact }})</option>@endforeach
      </select></div>
  </div>
  <div class="modal-footer justify-content-between">
    <button type="submit" form="dealProposal" class="btn btn-outline-secondary btn-sm d-none" id="dealProposalBtn"><i class="bi bi-file-earmark-text me-1"></i>{{ __('Proposal') }}</button>
    <button type="submit" form="dealDelete" class="btn btn-outline-danger btn-sm d-none" id="dealDeleteBtn" onclick="return confirm('{{ __('Remove this deal?') }}')">{{ __('Delete') }}</button>
    <button class="btn btn-primary">{{ __('Save') }}</button>
  </div>
</form>
<form id="dealDelete" method="post">@csrf @method('DELETE')</form>
<form id="dealProposal" method="post">@csrf</form></div></div>

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
const proposalBase = @json(url('proposals/deal'));
function setDeal(url, method, title, d) {
  const f = document.getElementById('dealForm'); f.action = url;
  document.getElementById('dealMethod').value = method;
  document.getElementById('dealTitle').textContent = title;
  document.getElementById('dTitle').value = d.title || ''; document.getElementById('dValue').value = d.value || '';
  document.getElementById('dDate').value = d.date || ''; document.getElementById('dOrigin').value = d.origin || ''; document.getElementById('dContact').value = d.contact_id || '';
  const del = document.getElementById('dealDeleteBtn');
  loadItems(d.id || null);
  const pb = document.getElementById('dealProposalBtn');
  if (d.id) { pb.classList.remove('d-none'); document.getElementById('dealProposal').action = proposalBase + '/' + d.id; } else pb.classList.add('d-none');
  if (d.id) { del.classList.remove('d-none'); document.getElementById('dealDelete').action = dealBase + '/' + d.id; } else del.classList.add('d-none');
}
let curDeal = null, catalog = [];
const itemsUrl = () => dealBase + '/' + curDeal + '/items';
const jsonH = {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token())};
function drawItems(d) {
  catalog = d.products;
  const list = document.getElementById('itemsList'); list.innerHTML = '';
  d.items.forEach(i => {
    const row = document.createElement('div'); row.className = 'd-flex justify-content-between align-items-center border-bottom py-1';
    const t = document.createElement('span'); t.textContent = i.quantity + ' × ' + i.name + ' (' + money(i.unit_price) + (i.discount_pct ? ' −' + i.discount_pct + '%' : '') + ')';
    const r = document.createElement('span'); r.textContent = money(i.total) + ' ';
    const x = document.createElement('button'); x.type = 'button'; x.className = 'btn btn-sm btn-link text-danger p-0'; x.innerHTML = '<i class="bi bi-x"></i>';
    x.onclick = async () => drawItems(await (await fetch(itemsUrl() + '/' + i.id, {method: 'DELETE', headers: jsonH})).json());
    r.appendChild(x); row.appendChild(t); row.appendChild(r); list.appendChild(row);
  });
  if (d.items.length) { const tot = document.createElement('div'); tot.className = 'text-end fw-bold pt-1'; tot.textContent = @json(__('Total')) + ': ' + money(d.total); list.appendChild(tot); }
  const sel = document.getElementById('iProduct'); sel.innerHTML = '<option value="">— ' + @json(__('Product')) + ' —</option>';
  catalog.forEach(p => { const o = document.createElement('option'); o.value = p.id; o.textContent = p.name + ' · ' + money(p.price); sel.appendChild(o); });
  document.getElementById('dValue').value = d.value || ''; document.getElementById('dValue').readOnly = d.items.length > 0;
}
async function loadItems(id) {
  curDeal = id; const box = document.getElementById('itemsBox');
  if (!id) { box.classList.add('d-none'); document.getElementById('dValue').readOnly = false; return; }
  box.classList.remove('d-none');
  drawItems(await (await fetch(itemsUrl(), {headers: {'Accept': 'application/json'}})).json());
}
document.getElementById('iProduct').addEventListener('change', e => { const p = catalog.find(x => String(x.id) === e.target.value); if (p) document.getElementById('iPrice').value = p.price; });
document.getElementById('iAdd').addEventListener('click', async () => {
  const body = {product_id: document.getElementById('iProduct').value || null, name: document.getElementById('iName').value || null, unit_price: document.getElementById('iPrice').value || null,
    quantity: document.getElementById('iQty').value || 1, discount_pct: document.getElementById('iDisc').value || 0};
  const r = await fetch(itemsUrl(), {method: 'POST', headers: jsonH, body: JSON.stringify(body)});
  if (!r.ok) { alert((await r.json()).message || @json(__('Could not add the item.'))); return; }
  ['iProduct', 'iName', 'iPrice'].forEach(k => document.getElementById(k).value = ''); document.getElementById('iQty').value = 1; document.getElementById('iDisc').value = 0;
  drawItems(await r.json());
});
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
