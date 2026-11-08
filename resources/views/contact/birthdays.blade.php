@extends('layouts.app-master')

@section('template_title', __('Birthdays'))

@section('content')
<div class="container py-4" style="max-width:760px">
  <a href="{{ route('contacts.index') }}" class="small">&larr; {{ __('Contacts') }}</a>
  <h4 class="mb-1 mt-1"><i class="bi bi-gift me-1"></i> {{ __('Birthdays') }}</h4>
  <p class="text-muted small">{{ __('Fill the birthday on the contact. Every morning the automation "A contact has a birthday today" runs for the people celebrating, so you can send a message, add a label or create a task.') }}</p>
  <div class="mb-3">@foreach ([7, 30, 90] as $d)<a href="{{ route('contacts.birthdays', ['days' => $d]) }}" class="btn btn-sm {{ $days === $d ? 'btn-primary' : 'btn-outline-secondary' }}">{{ $d }} {{ __('days') }}</a>@endforeach</div>
  <table class="table table-sm align-middle"><tbody>
    @forelse ($rows as $r)
      <tr><td><a href="{{ route('contacts.show', $r['contact']->id) }}">{{ $r['contact']->name }}</a></td>
        <td>{{ $r['date']->format('d/m') }}</td>
        <td class="text-muted small">{{ $r['in_days'] === 0 ? __('Today') : __('in :n days', ['n' => $r['in_days']]) }}</td>
        <td class="text-muted small">{{ $r['turns'] }} {{ __('years') }}</td></tr>
    @empty
      <tr><td class="text-muted">{{ __('No birthdays in the period.') }}</td></tr>
    @endforelse
  </tbody></table>
</div>
@endsection
