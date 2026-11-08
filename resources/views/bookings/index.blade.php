@extends('layouts.app-master')

@section('template_title', __('Bookings'))

@section('content')
<div class="container py-4" style="max-width:980px">
  <h4 class="mb-1"><i class="bi bi-calendar-check me-1"></i> {{ __('Bookings') }}</h4>
  <p class="text-muted small">{{ __('A public page where customers pick a free time. Times follow your opening hours.') }} <a href="{{ route('hours.edit') }}">{{ __('Opening hours') }}</a></p>
  @include('layouts.partials.messages')
  <form method="POST" action="{{ route('bookings.update') }}" class="card p-3 mb-3">
    @csrf @method('PUT')
    <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="bkOn" @checked(old('enabled', $cfg->enabled))><label class="form-check-label fw-semibold" for="bkOn">{{ __('Accept bookings') }}</label></div>
    @if ($cfg->exists)<div class="small mb-2">{{ __('Public link') }}: <a href="{{ route('booking-public.show', $cfg->slug) }}" target="_blank" rel="noopener">{{ route('booking-public.show', $cfg->slug) }}</a></div>@endif
    <div class="row g-2 mb-2">
      <div class="col-md-6"><label class="form-label small">{{ __('Title') }}</label><input name="title" class="form-control" required maxlength="120" value="{{ old('title', $cfg->title) }}"></div>
      <div class="col-md-2"><label class="form-label small">{{ __('Minutes') }}</label><input type="number" name="duration_minutes" min="10" max="240" class="form-control" value="{{ old('duration_minutes', $cfg->duration_minutes) }}"></div>
      <div class="col-md-2"><label class="form-label small">{{ __('Notice (hours)') }}</label><input type="number" name="min_notice_hours" min="0" max="720" class="form-control" value="{{ old('min_notice_hours', $cfg->min_notice_hours) }}"></div>
      <div class="col-md-2"><label class="form-label small">{{ __('Days ahead') }}</label><input type="number" name="max_days" min="1" max="180" class="form-control" value="{{ old('max_days', $cfg->max_days) }}"></div>
    </div>
    <textarea name="description" rows="2" maxlength="1000" class="form-control mb-2" placeholder="{{ __('Text shown to the customer') }}">{{ old('description', $cfg->description) }}</textarea>
    <div><button class="btn btn-primary btn-sm">{{ __('Save') }}</button></div>
  </form>
  <h6>{{ __('Upcoming') }}</h6>
  <table class="table table-sm align-middle"><tbody>
    @forelse ($upcoming as $b)
      <tr><td>{{ $b->starts_at->timezone(\App\Models\ServiceHours::for($b->user_id)->timezone ?: config('app.timezone'))->format('d/m H:i') }}</td><td>{{ $b->name }} <span class="small text-muted">{{ $b->phone }}</span></td>
        <td class="text-end"><form method="POST" action="{{ route('bookings.cancel', $b->id) }}" onsubmit="return confirm('{{ __('Cancel this booking?') }}')">@csrf<button class="btn btn-sm btn-outline-danger">{{ __('Cancel') }}</button></form></td></tr>
    @empty
      <tr><td class="text-muted">{{ __('No upcoming bookings.') }}</td></tr>
    @endforelse
  </tbody></table>
</div>
@endsection
