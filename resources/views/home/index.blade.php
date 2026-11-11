@extends('layouts.app-master')

@section('content')
@php
    $c = $mc['counts'];
    $fmt = fn ($m) => $m === null ? '—' : ($m < 60 ? $m . ' min' : ($m < 1440 ? floor($m / 60) . 'h' . str_pad($m % 60, 2, '0', STR_PAD_LEFT) : floor($m / 1440) . 'd'));
    $delta = function ($a) {
        $d = $a['now'] - $a['before'];
        return $d === 0 ? '' : ($d > 0 ? '▲ ' . $d : '▼ ' . abs($d));
    };
@endphp
<style>
    .mc .kpi { border: 0; border-left: 4px solid var(--kc, #adb5bd); border-radius: .75rem; transition: transform .12s, box-shadow .12s; }
    .mc a.kpi:hover { transform: translateY(-2px); box-shadow: 0 .5rem 1rem rgba(0,0,0,.1) !important; }
    .mc .kpi .n { font-size: 2rem; font-weight: 700; line-height: 1; }
    .mc .kpi.hot { background: #fff5f5; }
    .mc .pulse { width: .6rem; height: .6rem; border-radius: 50%; background: #dc3545; display: inline-block; animation: mcpulse 1.4s infinite; }
    @keyframes mcpulse { 0% { box-shadow: 0 0 0 0 rgba(220,53,69,.6); } 70% { box-shadow: 0 0 0 .5rem rgba(220,53,69,0); } 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); } }
    .mc .q-row { display: flex; gap: .75rem; align-items: center; padding: .65rem 1rem; border-bottom: 1px solid #eef0f2; color: inherit; text-decoration: none; }
    .mc .q-row:hover { background: #f8f9fa; }
    .mc .q-row:last-child { border-bottom: 0; }
    .mc .q-ico { width: 2rem; height: 2rem; border-radius: 50%; display: grid; place-items: center; background: #eef0f2; flex: 0 0 auto; }
    .mc .q-main { min-width: 0; flex: 1; }
    .mc .q-main .t { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mc .tile { border: 1px solid #e9ecef; border-radius: .75rem; padding: .85rem; height: 100%; }
    .mc .tile.down { border-color: #f1aeb5; background: #fff5f5; }
    .mc .bars { display: flex; align-items: flex-end; gap: 6px; height: 74px; }
    .mc .bars span { flex: 1; background: #0d6efd; border-radius: 4px 4px 0 0; min-height: 3px; opacity: .85; }
    .mc .bars span:last-child { opacity: 1; background: #198754; }
    .mc .bar-lbl { display: flex; gap: 6px; font-size: .7rem; color: #6c757d; }
    .mc .bar-lbl span { flex: 1; text-align: center; }
</style>

<div class="py-4 mc">
    @include('layouts.partials.messages')

    <div class="d-flex justify-content-between flex-wrap align-items-center gap-2 pb-2 mb-3 border-bottom">
        <div>
            <h1 class="h2 mb-0"><i class="bi bi-broadcast-pin"></i> {{ __('Mission control') }}</h1>
            <div class="text-muted small">{{ __('Updated at :time', ['time' => $mc['generated_at']->format('H:i')]) }} · <a href="" onclick="location.reload(); return false;">{{ __('Refresh') }}</a> · {{ __('refreshes by itself every minute') }}</div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            @teamcan('chat')<a href="{{ route('chat.index') }}" class="btn btn-sm btn-primary"><i class="bi bi-chat-dots"></i> {{ __('Open chat') }}</a>@endteamcan
            @teamcan('campaigns.view')<a href="{{ route('campaigns.create') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle"></i> {{ __('New Campaign') }}</a>@endteamcan
            @teamcan('reports')<a href="{{ route('stats.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-bar-chart"></i> {{ __('Statistics') }}</a>@endteamcan
        </div>
    </div>

    {{-- alerts --}}
    @foreach ($mc['alerts'] as $a)
        <a href="{{ $a['url'] }}" class="alert alert-{{ $a['level'] }} py-2 d-flex align-items-center gap-2 text-decoration-none mb-2">
            <i class="bi {{ $a['icon'] }}"></i><span>{{ $a['text'] }}</span><i class="bi bi-chevron-right ms-auto small"></i>
        </a>
    @endforeach

    {{-- what needs attention --}}
    @teamcan('chat')
    <div class="row g-3 mb-4">
        @foreach ([
            ['human', $c['human'], __('Asked for a person'), 'bi-headset', '#dc3545', 'human', true],
            ['waiting', $c['waiting'], __('Waiting for our answer'), 'bi-hourglass-top', '#fd7e14', 'waiting', false],
            ['pending', $c['pending'], __('Pending reply'), 'bi-flag', '#ffc107', 'pending', false],
            ['unassigned', $c['unassigned'], __('Unanswered and unassigned'), 'bi-person-dash', '#6f42c1', 'unassigned', false],
            ['open', $c['open'], __('Open conversations'), 'bi-chat-left-text', '#0d6efd', 'all', false],
        ] as [$k, $n, $label, $icon, $color, $filter, $hotIfAny])
            <div class="col-6 col-lg">
                <a href="{{ route('chat.index', ['filter' => $filter]) }}" class="card kpi shadow-sm text-decoration-none text-body h-100 {{ $hotIfAny && $n ? 'hot' : '' }}" style="--kc: {{ $color }}">
                    <div class="card-body py-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="n">{{ $n }}</div>
                            @if ($hotIfAny && $n)<span class="pulse" title="{{ __('Needs attention now') }}"></span>@else<i class="bi {{ $icon }} fs-4" style="color: {{ $color }}"></i>@endif
                        </div>
                        <div class="small text-muted mt-2">{{ $label }}</div>
                        @if ($k === 'waiting' && $c['oldest_min'])<div class="small" style="color: {{ $color }}">{{ __('longest: :t', ['t' => $fmt($c['oldest_min'])]) }}</div>@endif
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-4 mb-4">
        {{-- priority queue --}}
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 fw-bold"><i class="bi bi-lightning-charge text-warning me-1"></i>{{ __('Needs attention now') }}</h5>
                    <span class="small text-muted">{{ __('a person asked · late · pending · longest waiting') }}</span>
                </div>
                @forelse ($mc['queue'] as $q)
                    @php [$chLabel, $chIcon] = \App\Services\Dashboard\MissionControl::CHANNEL_LABELS[$q['channel']] ?? [ucfirst($q['channel']), 'bi-chat']; @endphp
                    <a class="q-row" href="{{ route('chat.index', ['instance' => $q['instance_id'], 'chat' => $q['id']]) }}">
                        <span class="q-ico" title="{{ $chLabel }}"><i class="bi {{ $chIcon }}"></i></span>
                        <span class="q-main">
                            <span class="d-block fw-semibold t">{{ $q['name'] }}
                                @if ($q['human'])<span class="badge text-bg-danger ms-1"><i class="bi bi-headset"></i> {{ __('Needs a person') }}</span>@endif
                                @if ($q['late'])<span class="badge text-bg-warning ms-1">{{ __('Late') }}</span>@endif
                                @if ($q['pending'])<span class="badge text-bg-secondary ms-1"><i class="bi bi-flag"></i> {{ __('Pending') }}</span>@endif
                            </span>
                            <span class="d-block small text-muted t">{{ $q['human'] && $q['human_reason'] ? $q['human_reason'] . ' · ' : '' }}{{ $q['preview'] ?: '…' }}</span>
                        </span>
                        <span class="text-end small flex-shrink-0">
                            <span class="d-block fw-semibold {{ $q['late'] || $q['human'] ? 'text-danger' : '' }}">{{ $fmt($q['waited']) }}</span>
                            <span class="text-muted">{{ $q['assignee'] ?: __('Unassigned') }}</span>
                        </span>
                    </a>
                @empty
                    <div class="text-center text-muted py-5"><i class="bi bi-emoji-smile fs-1 d-block mb-2 text-success"></i>{{ __('All caught up: nobody is waiting for an answer.') }}</div>
                @endforelse
            </div>
        </div>

        {{-- today --}}
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3"><h5 class="card-title mb-0 fw-bold"><i class="bi bi-calendar-day text-primary me-1"></i>{{ __('Today') }}</h5></div>
                <div class="card-body">
                    <div class="row g-3 text-center">
                        @foreach ([['in', __('Received'), 'bi-box-arrow-in-down'], ['out', __('Sent'), 'bi-box-arrow-up'], ['new', __('New conversations'), 'bi-person-plus'], ['ai', __('AI replies'), 'bi-robot']] as [$k, $lbl, $ic])
                            <div class="col-6">
                                <div class="fs-3 fw-bold">{{ $mc['today'][$k]['now'] }}</div>
                                <div class="small text-muted"><i class="bi {{ $ic }}"></i> {{ $lbl }}</div>
                                <div class="small {{ $mc['today'][$k]['now'] >= $mc['today'][$k]['before'] ? 'text-success' : 'text-muted' }}">{{ $delta($mc['today'][$k]) ?: '·' }} <span class="text-muted">{{ __('vs yesterday') }}</span></div>
                            </div>
                        @endforeach
                    </div>
                    <hr>
                    <div class="small text-muted mb-1">{{ __('Messages, last 7 days') }}</div>
                    <div class="bars">@foreach ($mc['week']['days'] as $d)<span style="height: {{ max(4, round($d['total'] / $mc['week']['max'] * 100)) }}%" title="{{ $d['date'] }}: {{ $d['total'] }}"></span>@endforeach</div>
                    <div class="bar-lbl">@foreach ($mc['week']['days'] as $d)<span>{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d') }}</span>@endforeach</div>
                </div>
            </div>
        </div>
    </div>
    @endteamcan

    {{-- channels --}}
    @teamcan('channels')
    <h5 class="fw-bold mb-3"><i class="bi bi-broadcast text-primary me-1"></i>{{ __('Channels') }}</h5>
    <div class="row g-3 mb-4">
        @forelse ($mc['channels'] as $t)
            <div class="col-6 col-md-4 col-xl-3">
                <div class="tile {{ $t['down'] ? 'down' : '' }}">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="fw-semibold"><i class="bi {{ $t['icon'] }} me-1"></i>{{ $t['label'] }}</span>
                        @if ($t['instances'] === 0)<span class="badge text-bg-light border">{{ __('history only') }}</span>
                        @elseif ($t['down'])<span class="badge text-bg-danger">{{ __('Disconnected') }}</span>
                        @else<span class="badge text-bg-success"><i class="bi bi-check2"></i> {{ __('Online') }}</span>@endif
                    </div>
                    <div class="row g-2 small">
                        <div class="col-6"><div class="fw-bold fs-5 {{ $t['waiting'] ? 'text-warning' : '' }}">{{ $t['waiting'] }}</div><div class="text-muted">{{ __('waiting') }}</div></div>
                        <div class="col-6"><div class="fw-bold fs-5">{{ $t['in_today'] }}<span class="text-muted fs-6"> / {{ $t['out_today'] }}</span></div><div class="text-muted">{{ __('in / out today') }}</div></div>
                        <div class="col-6"><div class="fw-semibold">{{ $fmt($t['first_response_min']) }}</div><div class="text-muted">{{ __('first reply (7d)') }}</div></div>
                        <div class="col-6"><div class="fw-semibold">{{ $t['ai_replies'] }}</div><div class="text-muted">{{ __('AI replies (7d)') }}</div></div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12"><div class="alert alert-info mb-0">{{ __('No channel connected yet.') }} <a href="{{ url('/instances') }}">{{ __('Connect WhatsApp') }}</a></div></div>
        @endforelse
    </div>
    @endteamcan

    <div class="row g-4 mb-4">
        {{-- workload --}}
        @if ($mc['workload'])
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white py-3"><h5 class="card-title mb-0 fw-bold"><i class="bi bi-people text-primary me-1"></i>{{ __('Team workload') }}</h5></div>
                <div class="card-body">
                    @php $maxOpen = max(1, collect($mc['workload'])->max('open')); @endphp
                    @foreach ($mc['workload'] as $w)
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small"><span class="{{ $w['unassigned'] ? 'text-danger fw-semibold' : '' }}">{{ $w['name'] }}</span><span class="text-muted">{{ $w['open'] }} {{ __('open') }}@if ($w['waiting']) · <strong class="text-warning">{{ $w['waiting'] }} {{ __('waiting') }}</strong>@endif</span></div>
                            <div class="progress" style="height: 6px"><div class="progress-bar {{ $w['unassigned'] ? 'bg-danger' : '' }}" style="width: {{ round($w['open'] / $maxOpen * 100) }}%"></div></div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- tasks --}}
        @if ($mc['tasks'])
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between"><h5 class="card-title mb-0 fw-bold"><i class="bi bi-check2-square text-primary me-1"></i>{{ __('Follow-ups') }}</h5><a class="small" href="{{ route('tasks.index') }}">{{ __('See all') }}</a></div>
                <div class="card-body">
                    <div class="d-flex gap-4 mb-3"><div><div class="fs-3 fw-bold {{ $mc['tasks']['overdue'] ? 'text-danger' : '' }}">{{ $mc['tasks']['overdue'] }}</div><div class="small text-muted">{{ __('overdue') }}</div></div><div><div class="fs-3 fw-bold">{{ $mc['tasks']['today'] }}</div><div class="small text-muted">{{ __('due today') }}</div></div></div>
                    @forelse ($mc['tasks']['list'] as $t)
                        <div class="small py-1 border-top d-flex justify-content-between gap-2"><span class="text-truncate">{{ $t['title'] }}</span><span class="{{ $t['overdue'] ? 'text-danger' : 'text-muted' }} flex-shrink-0">{{ $t['due'] }}</span></div>
                    @empty
                        <div class="small text-muted">{{ __('Nothing due today.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
        @endif

        {{-- sales --}}
        @if ($mc['sales'])
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between"><h5 class="card-title mb-0 fw-bold"><i class="bi bi-kanban text-primary me-1"></i>{{ __('Sales') }}</h5><a class="small" href="{{ route('pipeline.index') }}">{{ __('Pipeline') }}</a></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6"><div class="fs-4 fw-bold">{{ $mc['sales']['open_count'] }}</div><div class="small text-muted">{{ __('open deals') }}</div></div>
                        <div class="col-6"><div class="fs-4 fw-bold">{{ number_format($mc['sales']['open_value'], 0, ',', '.') }}</div><div class="small text-muted">{{ __('in the funnel') }}</div></div>
                        <div class="col-6"><div class="fs-4 fw-bold text-success">{{ $mc['sales']['won_count'] }}</div><div class="small text-muted">{{ __('won this month') }}</div></div>
                        <div class="col-6"><div class="fs-4 fw-bold text-success">{{ number_format($mc['sales']['won_value'], 0, ',', '.') }}</div><div class="small text-muted">{{ __('revenue this month') }}</div></div>
                    </div>
                    @if ($mc['sales']['closing_soon'])<div class="small mt-3 text-warning"><i class="bi bi-calendar-event"></i> {{ trans_choice(':n deal is due to close within 7 days|:n deals are due to close within 7 days', $mc['sales']['closing_soon'], ['n' => $mc['sales']['closing_soon']]) }}</div>@endif
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- campaign sending health + plan + support --}}
    <div class="row g-4">
        <div class="col-lg-8">
            @if ($mc['sending'])
                <div class="card border-0 shadow-sm rounded-3 mb-4">
                    <div class="card-body d-flex gap-4 align-items-center flex-wrap">
                        <div class="fw-bold"><i class="bi bi-send-check text-info me-1"></i>{{ __('Campaign sending') }}</div>
                        <div><span class="fs-4 fw-bold">{{ $mc['sending']['delivery'] }}</span><span class="text-muted small"> {{ __('delivery rate') }}</span></div>
                        <div><span class="fs-4 fw-bold {{ $mc['sending']['errors'] ? 'text-danger' : '' }}">{{ $mc['sending']['errors'] }}%</span><span class="text-muted small"> {{ __('failures') }}</span></div>
                        @teamcan('campaigns.view')<a class="ms-auto btn btn-sm btn-outline-secondary" href="{{ route('campaigns.index') }}">{{ __('Campaigns') }}</a>@endteamcan
                    </div>
                </div>
            @endif
            @include('billing.partials.dashboard-card')
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm rounded-3 mb-4">
                <div class="card-header bg-white py-3 text-center"><h5 class="card-title mb-0 fw-bold">{{ __('Support & activation') }}</h5></div>
                <div class="card-body text-center py-4">
                    <i class="bi bi-headset fs-1 text-primary mb-3"></i>
                    <p>{{ __('Questions or license upgrade?') }}</p>
                    <a href="https://wa.me/{{ env('WHATSAPP_CONTACT_TEST') }}" target="_blank" rel="noopener" class="btn btn-success w-100"><i class="bi bi-whatsapp"></i> {{ __('Contact support') }}</a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // keep the panel live without a websocket: reload every minute while the tab is visible
    setInterval(function () { if (!document.hidden) location.reload(); }, 60000);
</script>
@endsection
