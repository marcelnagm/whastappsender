@extends('layouts.app-master')

@section('template_title', __('Statistics'))

@section('content')
@php
  $meta = [
    'whatsapp'  => ['WhatsApp',  'bi-whatsapp',  '#25d366'],
    'telegram'  => ['Telegram',  'bi-telegram',  '#229ed9'],
    'instagram' => ['Instagram', 'bi-instagram', '#d6249f'],
    'tiktok'    => ['TikTok',    'bi-tiktok',    '#fe2c55'],
  ];
  $fmt = function ($min) {
      if ($min === null) return '—';
      if ($min < 60) return $min . ' min';
      return floor($min / 60) . 'h ' . ($min % 60) . 'min';
  };
@endphp
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-xl-11">
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <h1 class="h3 mb-0 fw-bold"><i class="bi bi-bar-chart-line text-primary me-2"></i>{{ __('Channel statistics') }}</h1>
      <div class="btn-group" role="group" aria-label="{{ __('Period') }}">
        @foreach ([7, 30, 90] as $d)
          <a href="{{ route('stats.index', ['days' => $d]) }}" class="btn btn-sm {{ $days === $d ? 'btn-primary' : 'btn-outline-primary' }}">{{ __(':n days', ['n' => $d]) }}</a>
        @endforeach
      </div>
    </div>

    <div class="row g-3 mb-4">
      @foreach ($channels as $key => $c)
        <div class="col-md-6 col-xl-3">
          <div class="card shadow-sm h-100" style="border-top: 4px solid {{ $meta[$key][2] }}">
            <div class="card-body">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <div class="fw-bold"><i class="bi {{ $meta[$key][1] }} me-1" style="color: {{ $meta[$key][2] }}"></i>{{ $meta[$key][0] }}</div>
                <span class="small text-muted">{{ __(':n connected', ['n' => $c['instances']]) }}</span>
              </div>
              <dl class="row mb-0 small">
                <dt class="col-7 fw-normal text-muted">{{ __('Conversations') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['chats'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('New in period') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['new_chats'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('Active in period') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['active_chats'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('Waiting for reply') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['waiting'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('Messages received') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['inbound'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('Messages sent') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['outbound'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('AI replies') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['ai_replies'] }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('First response (avg)') }}</dt><dd class="col-5 text-end fw-semibold">{{ $fmt($c['first_response_min']) }}</dd>
                <dt class="col-7 fw-normal text-muted">{{ __('Contacts') }}</dt><dd class="col-5 text-end fw-semibold">{{ $c['contacts'] }}@if($key !== 'whatsapp') <span class="text-muted fw-normal">({{ __(':n linked', ['n' => $c['linked']]) }})</span>@endif</dd>
              </dl>
            </div>
          </div>
        </div>
      @endforeach
    </div>

    <div class="card shadow-sm mb-4"><div class="card-body">
      <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
        <h2 class="h6 mb-0">{{ __('Messages per day (last 30 days at most)') }}</h2>
        <div class="small">
          @foreach ($meta as $k => $m)<span class="me-3"><span style="display:inline-block;width:10px;height:10px;background:{{ $m[2] }};border-radius:2px"></span> {{ $m[0] }}</span>@endforeach
        </div>
      </div>
      <div class="d-flex align-items-end gap-1" style="height:160px" role="img" aria-label="{{ __('Messages per day') }}">
        @foreach ($daily as $date => $byChannel)
          @php $total = array_sum($byChannel); @endphp
          <div class="flex-fill d-flex flex-column justify-content-end" style="height:100%;min-width:4px" title="{{ $date }}: {{ $total }}">
            @foreach ($byChannel as $k => $n)
              @if ($n > 0)<div style="height: {{ round($n / $max * 100, 1) }}%; background: {{ $meta[$k][2] }}"></div>@endif
            @endforeach
          </div>
        @endforeach
      </div>
      <div class="d-flex justify-content-between small text-muted mt-1"><span>{{ array_key_first($daily) }}</span><span>{{ array_key_last($daily) }}</span></div>
    </div></div>

    <div class="card shadow-sm">
      <div class="card-header bg-white fw-semibold">{{ __('By connection') }}</div>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Channel') }}</th><th>{{ __('Name') }}</th><th class="text-end">{{ __('Conversations') }}</th><th class="text-end">{{ __('Messages received') }}</th><th class="text-end">{{ __('Messages sent') }}</th></tr></thead>
          <tbody>
          @forelse ($instances as $i)
            <tr>
              <td><i class="bi {{ $meta[$i['channel']][1] }} me-1" style="color: {{ $meta[$i['channel']][2] }}"></i>{{ $meta[$i['channel']][0] }}</td>
              <td>{{ $i['name'] }}</td>
              <td class="text-end">{{ $i['chats'] }}</td><td class="text-end">{{ $i['inbound'] }}</td><td class="text-end">{{ $i['outbound'] }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="text-center text-muted py-4">{{ __('No connections yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>
@endsection
