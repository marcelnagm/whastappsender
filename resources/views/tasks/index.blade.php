@extends('layouts.app-master')

@section('template_title', __('Follow-ups'))

@section('content')
<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-alarm me-2"></i>{{ __('Follow-ups') }}</h1>
    <div class="btn-group btn-group-sm" role="group">
      <a href="{{ route('tasks.index', ['tab' => $tab, 'scope' => 'mine']) }}" class="btn btn-outline-secondary {{ $scope === 'mine' ? 'active' : '' }}">{{ __('Mine') }}</a>
      <a href="{{ route('tasks.index', ['tab' => $tab, 'scope' => 'all']) }}" class="btn btn-outline-secondary {{ $scope === 'all' ? 'active' : '' }}">{{ __('Everyone') }}</a>
    </div>
  </div>

  @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
  @if ($errors->any())<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

  <form method="POST" action="{{ route('tasks.store') }}" class="card card-body border-0 shadow-sm mb-3">
    @csrf
    <div class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label small mb-1">{{ __('What to do') }}</label><input name="title" class="form-control" maxlength="160" required placeholder="{{ __('Call back about the quote') }}"></div>
      <div class="col-md-2"><label class="form-label small mb-1">{{ __('When') }}</label><input type="datetime-local" name="due_at" class="form-control"><select name="recurrence" class="form-select form-select-sm mt-1" title="{{ __('Repeat') }}"><option value="">{{ __('Does not repeat') }}</option><option value="daily">{{ __('Every day') }}</option><option value="weekly">{{ __('Every week') }}</option><option value="monthly">{{ __('Every month') }}</option></select></div>
      <div class="col-md-2"><label class="form-label small mb-1">{{ __('Who') }}</label>
        <select name="assigned_to" class="form-select">@foreach ($people as $p)<option value="{{ $p->id }}" @selected($p->id === $me->id)>{{ $p->name }}</option>@endforeach</select></div>
      <div class="col-md-3"><label class="form-label small mb-1">{{ __('Contact (optional)') }}</label>
        <select name="contact_id" class="form-select"><option value="">—</option>@foreach ($contacts as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach</select></div>
      <div class="col-md-1 d-grid"><button class="btn btn-primary"><i class="bi bi-plus-lg"></i></button></div>
    </div>
  </form>

  <ul class="nav nav-pills mb-3">
    @foreach (['open' => __('Open'), 'today' => __('Today'), 'overdue' => __('Overdue'), 'done' => __('Done')] as $key => $label)
      <li class="nav-item"><a class="nav-link {{ $tab === $key ? 'active' : '' }}" href="{{ route('tasks.index', ['tab' => $key, 'scope' => $scope]) }}">{{ $label }}
        <span class="badge {{ $key === 'overdue' && $counts[$key] ? 'bg-danger' : 'bg-secondary' }}">{{ $counts[$key] }}</span></a></li>
    @endforeach
  </ul>

  <div class="card border-0 shadow-sm">
    <ul class="list-group list-group-flush">
      @forelse ($tasks as $t)
        <li class="list-group-item d-flex align-items-start gap-3">
          <form method="POST" action="{{ route('tasks.done', $t->id) }}">@csrf
            <button class="btn btn-sm {{ $t->isDone() ? 'btn-success' : 'btn-outline-success' }}" title="{{ $t->isDone() ? __('Reopen') : __('Mark as done') }}"><i class="bi bi-check-lg"></i></button>
          </form>
          <div class="flex-grow-1">
            <div class="{{ $t->isDone() ? 'text-decoration-line-through text-muted' : 'fw-semibold' }}">{{ $t->title }}</div>
            <div class="small text-muted">
              @if ($t->due_at)<span class="{{ $t->isOverdue() ? 'text-danger fw-semibold' : '' }}"><i class="bi bi-clock"></i> {{ $t->due_at->format('d/m/Y H:i') }}</span> · @endif
              @if ($t->recurrence)<i class="bi bi-arrow-repeat" title="{{ __('Repeats') }}"></i> {{ ['daily' => __('Every day'), 'weekly' => __('Every week'), 'monthly' => __('Every month')][$t->recurrence] ?? '' }} · @endif
              <i class="bi bi-person"></i> {{ optional($t->assignee)->name ?: '—' }}
              @if ($t->contact) · <i class="bi bi-person-lines-fill"></i> <a href="{{ route('contacts.show', $t->contact_id) }}">{{ $t->contact->name }}</a>@endif
            </div>
            @if ($t->notes)<div class="small mt-1">{{ $t->notes }}</div>@endif
          </div>
          <form method="POST" action="{{ route('tasks.destroy', $t->id) }}" onsubmit="return confirm(@js(__('Remove this task?')))">@csrf @method('DELETE')
            <button class="btn btn-sm btn-outline-danger" title="{{ __('Remove') }}"><i class="bi bi-trash"></i></button>
          </form>
        </li>
      @empty
        <li class="list-group-item text-muted text-center py-4">{{ __('Nothing here.') }}</li>
      @endforelse
    </ul>
  </div>
</div>
@endsection
