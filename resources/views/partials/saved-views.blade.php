@php
  $svViews = \App\Services\SavedViews::visible(auth()->user(), $scope);
  $svQuery = \App\Services\SavedViews::clean($scope, (string) request()->getQueryString());
  if ($scope === 'pipeline' && $svQuery === '' && isset($pipeline)) { $svQuery = 'pipeline=' . $pipeline->id; }
@endphp
<div class="dropdown d-inline-block">
  <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="bi bi-bookmark-star me-1"></i>{{ __('Views') }}@if ($svViews->count()) <span class="badge text-bg-light">{{ $svViews->count() }}</span>@endif</button>
  <div class="dropdown-menu dropdown-menu-end p-2" style="min-width:280px">
    @forelse ($svViews as $v)
      <div class="d-flex align-items-center justify-content-between gap-2 px-1">
        <a class="dropdown-item rounded flex-grow-1 text-truncate" href="{{ \App\Services\SavedViews::url($v) }}">{{ $v->name }} @unless ($v->shared)<i class="bi bi-lock-fill small text-muted" title="{{ __('Only you') }}"></i>@endunless</a>
        @if (\App\Services\SavedViews::canDelete($v, auth()->user()))
          <form method="POST" action="{{ route('saved-views.destroy', $v->id) }}" onsubmit="return confirm('{{ __('Delete?') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger p-0" title="{{ __('Delete') }}"><i class="bi bi-x-lg"></i></button></form>
        @endif
      </div>
    @empty
      <div class="small text-muted px-2 py-1">{{ __('No saved views yet.') }}</div>
    @endforelse
    <div class="dropdown-divider"></div>
    @if ($svQuery !== '')
      <form method="POST" action="{{ route('saved-views.store') }}" class="px-2">
        @csrf
        <input type="hidden" name="scope" value="{{ $scope }}"><input type="hidden" name="query" value="{{ $svQuery }}">
        <div class="small text-muted mb-1">{{ __('Save the filters you are using now') }}</div>
        <input name="name" class="form-control form-control-sm mb-1" required maxlength="80" placeholder="{{ __('Name of the view') }}">
        <div class="form-check small"><input type="checkbox" class="form-check-input" name="shared" value="1" id="sv-shared-{{ $scope }}" checked><label class="form-check-label" for="sv-shared-{{ $scope }}">{{ __('Share with the team') }}</label></div>
        <button class="btn btn-sm btn-primary w-100 mt-1">{{ __('Save view') }}</button>
      </form>
    @else
      <div class="small text-muted px-2">{{ __('Apply some filters first, then save them here.') }}</div>
    @endif
  </div>
</div>
