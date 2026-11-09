@extends('layouts.app-master')
@section('template_title', __('Business insights'))
@section('content')
<div class="container py-4" style="max-width:1000px">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h1 class="h4 mb-0"><i class="bi bi-lightbulb me-2"></i>{{ __('Business insights') }}</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('ai.knowledge.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('Business knowledge') }}</a>
            <a href="{{ route('ai.index') }}" class="btn btn-sm btn-outline-secondary">{{ __('AI agents') }}</a>
        </div>
    </div>
    <p class="text-muted">{{ __('The AI reads your numbers (computed by the system, never by the AI) and what you taught it about your business, and writes a short report with findings and actions.') }}</p>

    @include('layouts.partials.messages')
    @unless ($ready)
        <div class="alert alert-warning">{{ __('Create and turn on an AI agent (with its API key) to generate insights.') }}</div>
    @endunless

    <form method="POST" action="{{ route('ai.insights.generate') }}" class="card card-body mb-4">
        @csrf
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small">{{ __('Type of business') }}</label>
                <select name="business_type" class="form-select">
                    @foreach ($types as $k => $t)<option value="{{ $k }}" @selected($lastType === $k)>{{ $t[0] }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small">{{ __('Period') }}</label>
                <select name="days" class="form-select">
                    @foreach ([7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $d => $l)<option value="{{ $d }}" @selected($d === 30)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3 d-grid"><button class="btn btn-primary" @disabled(!$ready)><i class="bi bi-stars me-1"></i>{{ __('Generate report') }}</button></div>
        </div>
    </form>

    <div class="row g-3 mb-4">
        <div class="col-md-6"><div class="card h-100"><div class="card-body">
            <h2 class="h6">{{ __('AI usage this month') }}</h2>
            <div class="small">{{ __('Reports') }}: <strong>{{ $quota['used'] }}</strong> {{ $quota['limit'] === null ? __('(no limit)') : '/ ' . $quota['limit'] }}</div>
            <div class="small">{{ __('Customer replies') }}: <strong>{{ $usage['by_kind']['reply']['calls'] ?? 0 }}</strong> · {{ number_format($usage['by_kind']['reply']['tokens'] ?? 0, 0, ',', '.') }} tokens</div>
            <div class="small">{{ __('Reports') }}: <strong>{{ $usage['by_kind']['insight']['calls'] ?? 0 }}</strong> · {{ number_format($usage['by_kind']['insight']['tokens'] ?? 0, 0, ',', '.') }} tokens</div>
            @foreach (array_slice($usage['by_model'], 0, 4) as $m)<div class="small text-muted">{{ $m['model'] }} · {{ $m['calls'] }} · {{ number_format($m['tokens'], 0, ',', '.') }} tokens</div>@endforeach
        </div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-body">
            <h2 class="h6">{{ __('Quality of AI answers (30 days)') }}</h2>
            <div class="small mb-1">👍 <strong>{{ $feedback['up'] }}</strong> &nbsp; 👎 <strong>{{ $feedback['down'] }}</strong> <span class="text-muted">— {{ __('rate answers in the chat: open the message menu of an AI answer') }}</span></div>
            @foreach ($feedback['bad'] as $b)
                <div class="small border-top pt-1 mt-1"><a href="{{ route('chat.index', ['chat' => $b->chat_id]) }}">{{ \Illuminate\Support\Str::limit($b->body, 90) }}</a>@if ($b->ai_feedback_note)<div class="text-muted">“{{ $b->ai_feedback_note }}”</div>@endif</div>
            @endforeach
            @if ($feedback['bad']->isNotEmpty())<div class="small text-muted mt-1">{{ __('Turn repeated problems into FAQs in Business knowledge.') }}</div>@endif
        </div></div></div>
    </div>

    <form method="POST" action="{{ route('ai.insights.schedule') }}" class="card card-body mb-4">
        @csrf @method('PUT')
        <h2 class="h6">{{ __('Automatic report') }}</h2>
        <div class="row g-2 align-items-end">
            <div class="col-md-3"><div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="enabled" value="1" id="sch-on" @checked($schedule->enabled ?? false)><label class="form-check-label" for="sch-on">{{ __('Send it to me by e-mail') }}</label></div></div>
            <div class="col-md-2"><select name="frequency" class="form-select form-select-sm">
                <option value="weekly" @selected(($schedule->frequency ?? 'weekly') === 'weekly')>{{ __('Every Monday') }}</option>
                <option value="monthly" @selected(($schedule->frequency ?? '') === 'monthly')>{{ __('1st of the month') }}</option></select></div>
            <div class="col-md-3"><select name="business_type" class="form-select form-select-sm">@foreach ($types as $k => $t)<option value="{{ $k }}" @selected(($schedule->business_type ?? $lastType) === $k)>{{ $t[0] }}</option>@endforeach</select></div>
            <div class="col-md-2"><select name="days" class="form-select form-select-sm">@foreach ([7 => __('Last 7 days'), 30 => __('Last 30 days'), 90 => __('Last 90 days'), 365 => __('Last 12 months')] as $d => $l)<option value="{{ $d }}" @selected(($schedule->period_days ?? 30) === $d)>{{ $l }}</option>@endforeach</select></div>
            <div class="col-md-4"><input type="text" name="whatsapp_number" class="form-control form-control-sm" value="{{ $schedule->whatsapp_number ?? '' }}" placeholder="{{ __('Also on WhatsApp: number with country code (optional)') }}"></div>
            <div class="col-md-2 d-grid"><button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button></div>
        </div>
        @if ($schedule && $schedule->last_error)<div class="small text-danger mt-2">{{ __('Last attempt failed') }}: {{ $schedule->last_error }}</div>@endif
        @if ($schedule && $schedule->last_run_at)<div class="small text-muted mt-2">{{ __('Last sent') }}: {{ $schedule->last_run_at->format('d/m/Y') }}</div>@endif
    </form>

    @if ($report)
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="small text-muted">{{ $types[$report->business_type][0] ?? $report->business_type }} · {{ __(':n days', ['n' => $report->period_days]) }} · {{ $report->created_at->format('d/m/Y H:i') }} · {{ $report->model }}</div>
            <form method="POST" action="{{ route('ai.insights.destroy', $report->id) }}" onsubmit="return confirm('{{ __('Delete?') }}')">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger">{{ __('Delete') }}</button></form>
        </div>
        @if ($report->summary)<div class="alert alert-info">{{ $report->summary }}</div>@endif

        <div class="row g-3 mb-3">
            @foreach ($report->blocks as $b)@if ($b['type'] === 'kpi')
                <div class="col-6 col-md-4"><div class="card h-100"><div class="card-body">
                    <div class="small text-muted">{{ $b['label'] }}</div><div class="fs-3 fw-bold">{{ $b['value'] }}</div>
                    @if ($b['hint'])<div class="small text-muted">{{ $b['hint'] }}</div>@endif
                </div></div></div>
            @endif @endforeach
        </div>

        @foreach ($report->blocks as $b)
            @if ($b['type'] === 'table')
                <div class="card mb-3"><div class="card-body">
                    @if ($b['title'])<h2 class="h6">{{ $b['title'] }}</h2>@endif
                    <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr>@foreach ($b['columns'] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                    <tbody>@foreach ($b['rows'] as $r)<tr>@foreach ($r as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody></table></div>
                </div></div>
            @elseif ($b['type'] === 'bars')
                @php $max = max(1, max($b['values'])); @endphp
                <div class="card mb-3"><div class="card-body">
                    @if ($b['title'])<h2 class="h6">{{ $b['title'] }}</h2>@endif
                    @foreach ($b['labels'] as $i => $l)
                        <div class="d-flex align-items-center mb-1 small"><div style="width:30%" class="text-truncate">{{ $l }}</div>
                            <div class="flex-grow-1 mx-2"><div class="bg-primary rounded" style="height:14px;width:{{ round(100 * max(0, $b['values'][$i]) / $max) }}%"></div></div>
                            <div style="width:70px" class="text-end">{{ rtrim(rtrim(number_format($b['values'][$i], 2, '.', ''), '0'), '.') }}</div></div>
                    @endforeach
                </div></div>
            @elseif ($b['type'] === 'text')
                <div class="card mb-3"><div class="card-body">
                    @if ($b['title'])<h2 class="h6">{{ $b['title'] }}</h2>@endif
                    <div style="white-space:pre-line">{{ $b['body'] }}</div>
                </div></div>
            @endif
        @endforeach

        @php $recs = collect($report->blocks)->where('type', 'recommendation'); @endphp
        @if ($recs->isNotEmpty())
            <h2 class="h6 mt-4">{{ __('Recommended actions') }}</h2>
            @foreach ($recs as $b)
                <div class="card mb-2 border-start border-4 border-{{ ['high' => 'danger', 'medium' => 'warning', 'low' => 'secondary'][$b['priority']] }}"><div class="card-body py-2">
                    <div class="fw-semibold">{{ $b['title'] }} <span class="badge text-bg-light">{{ __($b['priority']) }}</span></div>
                    <div class="small">{{ $b['body'] }}</div>
                </div></div>
            @endforeach
        @endif

        <details class="mt-3"><summary class="small text-muted">{{ __('Numbers the AI received') }}</summary><pre class="small bg-light p-2 mt-2" style="max-height:300px;overflow:auto">{{ json_encode($report->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></details>
    @endif

    @if ($reports->count() > 1)
        <h2 class="h6 mt-4">{{ __('Previous reports') }}</h2>
        <ul class="list-unstyled small">
            @foreach ($reports as $r)<li><a href="{{ route('ai.insights.index', ['r' => $r->id]) }}">{{ $r->created_at->format('d/m/Y H:i') }}</a> · {{ $types[$r->business_type][0] ?? $r->business_type }} · {{ __(':n days', ['n' => $r->period_days]) }}</li>@endforeach
        </ul>
    @endif
</div>
@endsection
