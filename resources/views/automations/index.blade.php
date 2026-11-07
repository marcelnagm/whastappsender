@extends('layouts.app-master')

@section('template_title', __('Automations'))

@section('content')
@php
  $trLabels = ['proposal.accepted' => __('A proposal is accepted'), 'proposal.declined' => __('A proposal is declined'), 'deal.created' => __('A deal is created'), 'deal.stage' => __('A deal moves to a stage'), 'deal.won' => __('A deal is won'), 'deal.lost' => __('A deal is lost'),
               'tag.added' => __('A label is applied to a conversation'), 'message.keyword' => __('A customer writes a word')];
  $acLabels = ['create_task' => __('Create a follow-up task'), 'add_tag' => __('Apply a label'), 'assign' => __('Assign the conversation'), 'move_deal' => __('Move the deal to a stage'), 'notify' => __('Notify someone')];
  $stagesJson = json_encode($stages->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->values());
  $tagsJson = json_encode($tags);
  $peopleJson = json_encode($people);
  $acJson = json_encode($acLabels);
  $i18n = json_encode(['title' => __('Task title'), 'hours' => __('due in (hours)'), 'message' => __('Message'), 'owner' => __('Account owner'), 'assignee' => __('Whoever has the conversation'), 'remove' => __('Remove')]);
@endphp
<div class="container py-4">
  <h1 class="h3 fw-bold mb-1"><i class="bi bi-diagram-3 me-2"></i>{{ __('Automations') }}</h1>
  <p class="text-muted small">{{ __('When something happens in the funnel or in the chat, do the routine work for the team. An automation runs once per deal / conversation in a chain, so they never loop.') }}</p>
  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6 class="fw-bold">{{ __('Your automations') }}</h6>
        <ul class="list-group list-group-flush">
          @forelse ($rules as $r)
            <li class="list-group-item px-0">
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                  <strong>{{ $r->name }}</strong> @unless($r->active)<span class="badge bg-secondary">{{ __('Paused') }}</span>@endunless
                  <div class="small text-muted">{{ __('When') }}: {{ $trLabels[$r->trigger] ?? $r->trigger }}</div>
                  <div class="small text-muted">{{ __('Then') }}: {{ collect($r->actions)->map(fn ($a) => $acLabels[$a['type']] ?? $a['type'])->implode(' → ') }}</div>
                  <div class="small text-muted">{{ __(':n run(s)', ['n' => $r->runs]) }}@if($r->last_run_at) · {{ $r->last_run_at->diffForHumans() }}@endif</div>
                </div>
                <div class="d-flex gap-1">
                  <form method="POST" action="{{ route('automations.toggle', $r->id) }}">@csrf<button class="btn btn-sm btn-outline-secondary" title="{{ $r->active ? __('Pause') : __('Resume') }}"><i class="bi bi-{{ $r->active ? 'pause' : 'play' }}"></i></button></form>
                  <form method="POST" action="{{ route('automations.destroy', $r->id) }}" onsubmit="return confirm('{{ __('Remove this automation?') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </div>
              </div>
            </li>
          @empty
            <li class="list-group-item px-0 text-muted small">{{ __('No automations yet.') }}</li>
          @endforelse
        </ul>
      </div></div>

      <div class="card border-0 shadow-sm"><div class="card-body">
        <h6 class="fw-bold">{{ __('Latest runs') }}</h6>
        <ul class="list-group list-group-flush small">
          @forelse ($runs as $run)
            <li class="list-group-item px-0"><span class="badge bg-{{ $run->status === 'ok' ? 'success' : ($run->status === 'partial' ? 'warning' : 'danger') }}">{{ $run->status }}</span>
              {{ $run->name }} · {{ $run->subject }} · <span class="text-muted">{{ \Illuminate\Support\Carbon::parse($run->created_at)->diffForHumans() }}</span>
              @if ($run->detail)<div class="text-danger">{{ $run->detail }}</div>@endif</li>
          @empty
            <li class="list-group-item px-0 text-muted">{{ __('Nothing ran yet.') }}</li>
          @endforelse
        </ul>
      </div></div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm"><div class="card-body">
        <h6 class="fw-bold">{{ __('New automation') }}</h6>
        <form method="POST" action="{{ route('automations.store') }}" id="am-form" data-stages="{{ $stagesJson }}" data-tags="{{ $tagsJson }}" data-people="{{ $peopleJson }}" data-ac="{{ $acJson }}" data-i18n="{{ $i18n }}" data-max="{{ $max }}">@csrf
          <input name="name" class="form-control mb-2" placeholder="{{ __('Name') }}" required maxlength="80" value="{{ old('name') }}">
          <label class="form-label small mb-1">{{ __('When') }}</label>
          <select name="trigger" id="am-trigger" class="form-select mb-2">@foreach ($triggers as $t)<option value="{{ $t }}">{{ $trLabels[$t] }}</option>@endforeach</select>
          <div id="am-cond" class="mb-2"></div>
          <label class="form-label small mb-1">{{ __('Then') }}</label>
          <div id="am-actions" class="mb-2"></div>
          <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="am-add"><i class="bi bi-plus"></i> {{ __('Add action') }}</button>
          <div><button class="btn btn-primary btn-sm">{{ __('Save automation') }}</button></div>
        </form>
      </div></div>
    </div>
  </div>
</div>
<script>
(function () {
  var f = document.getElementById('am-form'), D = function (k) { return JSON.parse(f.dataset[k]); },
      stages = D('stages'), tags = D('tags'), people = D('people'), ac = D('ac'), L = D('i18n'), max = parseInt(f.dataset.max, 10), n = 0;
  function el(t, a) { var e = document.createElement(t); Object.keys(a || {}).forEach(function (k) { e[k] = a[k]; }); return e; }
  function sel(name, items, blank) { var s = el('select', { name: name, className: 'form-select form-select-sm' }); if (blank) s.appendChild(el('option', { value: '', textContent: blank })); items.forEach(function (i) { s.appendChild(el('option', { value: i[0], textContent: i[1] })); }); return s; }
  var stageOpts = stages.map(function (s) { return [s.id, s.name]; }), tagOpts = tags.map(function (t) { return [t.id, t.name]; });
  var whoOpts = [['owner', L.owner], ['assignee', L.assignee]].concat(people.map(function (p) { return [p.id, p.name]; }));
  function cond() {
    var b = document.getElementById('am-cond'), t = document.getElementById('am-trigger').value; b.innerHTML = '';
    if (t === 'deal.stage') b.appendChild(sel('stage_id', stageOpts, '—'));
    if (t === 'tag.added') b.appendChild(sel('tag_id', tagOpts, '—'));
    if (t === 'message.keyword') b.appendChild(el('input', { name: 'keywords', className: 'form-control form-control-sm', placeholder: 'preço, orçamento' }));
  }
  function action() {
    var box = document.getElementById('am-actions'); if (box.children.length >= max) return;
    var i = n++, r = el('div', { className: 'border rounded p-2 mb-2' }), top = el('div', { className: 'd-flex gap-2 mb-1' }), body = el('div', { className: 'd-flex flex-wrap gap-2' });
    var type = sel('actions[' + i + '][type]', Object.keys(ac).map(function (k) { return [k, ac[k]]; }));
    var rm = el('button', { type: 'button', className: 'btn btn-sm btn-outline-danger', title: L.remove, innerHTML: '<i class="bi bi-x"></i>' }); rm.onclick = function () { r.remove(); };
    top.appendChild(type); top.appendChild(rm); r.appendChild(top); r.appendChild(body);
    function build() {
      body.innerHTML = ''; var p = 'actions[' + i + ']', t = type.value;
      if (t === 'create_task') { body.appendChild(el('input', { name: p + '[title]', className: 'form-control form-control-sm', placeholder: L.title, style: 'min-width:180px' })); body.appendChild(el('input', { name: p + '[due_hours]', type: 'number', min: 0, value: 24, className: 'form-control form-control-sm', style: 'width:90px', title: L.hours })); body.appendChild(sel(p + '[assignee]', whoOpts)); }
      if (t === 'add_tag') body.appendChild(sel(p + '[tag_id]', tagOpts));
      if (t === 'assign') body.appendChild(sel(p + '[assignee]', whoOpts.filter(function (w) { return w[0] !== 'assignee'; })));
      if (t === 'move_deal') body.appendChild(sel(p + '[stage_id]', stageOpts));
      if (t === 'notify') { body.appendChild(el('input', { name: p + '[message]', className: 'form-control form-control-sm', placeholder: L.message, style: 'min-width:180px' })); body.appendChild(sel(p + '[assignee]', whoOpts)); }
    }
    type.onchange = build; build(); box.appendChild(r);
  }
  document.getElementById('am-trigger').onchange = cond; document.getElementById('am-add').onclick = action; cond(); action();
})();
</script>
@endsection
