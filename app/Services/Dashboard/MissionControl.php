<?php

namespace App\Services\Dashboard;

use App\Models\Chat;
use App\Models\Deal;
use App\Models\Instance;
use App\Models\SlaSetting;
use App\Models\Task;
use App\Models\User;
use App\Models\WhatsappJob;
use App\Services\Stats\ChannelStats;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the dashboard shows, in one place: what needs a person right now (customers waiting, people requested,
 * conversations marked pending), the health of every channel, today's numbers, the team's workload and, when allowed,
 * deals and tasks. All numbers are counted by the system; nothing here is estimated.
 */
class MissionControl
{
    public const CHANNEL_LABELS = [
        'whatsapp' => ['WhatsApp', 'bi-whatsapp'], 'telegram' => ['Telegram', 'bi-telegram'], 'instagram' => ['Instagram', 'bi-instagram'],
        'facebook' => ['Facebook', 'bi-facebook'], 'tiktok' => ['TikTok', 'bi-tiktok'], 'email' => ['E-mail', 'bi-envelope-at'], 'webchat' => ['Website chat', 'bi-chat-dots'],
    ];

    /**
     * @param  int|null  $only  attendant who only sees their own (and unassigned) conversations
     * @param  array<string,bool>  $can  what the signed-in person may see: chat, deals, tasks, channels, reports
     */
    public function build(User $owner, ?int $only = null, array $can = []): array
    {
        $now = now();
        $today = $now->copy()->startOfDay();
        $sla = SlaSetting::for($owner->id);
        $lateAfter = max(1, (int) $sla->first_response_minutes);

        $base = fn () => Chat::query()->where('chats.user_id', $owner->id)->where('chats.archived', false)->whereNull('chats.closed_at')
            ->whereNotNull('chats.last_message_at')
            ->where(fn ($w) => $w->whereNull('chats.snoozed_until')->orWhere('chats.snoozed_until', '<=', $now))
            ->when($only, fn ($q) => $q->where(fn ($w) => $w->whereNull('chats.assigned_to')->orWhere('chats.assigned_to', $only)));

        $queue = $can['chat'] ?? true ? $this->queue($base, $now, $lateAfter) : [];

        $counts = [
            'waiting' => (clone $base())->where('last_message_from_me', false)->count(),
            'human' => (clone $base())->whereNotNull('needs_human_at')->count(),
            'pending' => (clone $base())->whereNotNull('pending_at')->count(),
            'unassigned' => (clone $base())->where('last_message_from_me', false)->whereNull('assigned_to')->count(),
            'late' => collect($queue)->where('late', true)->count(),
            'open' => (clone $base())->count(),
        ];
        // "late" only counts the visible queue (the 60 most urgent); with more than that, show at least what is listed
        $counts['oldest_min'] = collect($queue)->max('waited');

        $channels = $this->channels($owner, $base, $today, $now);
        $stats = app(ChannelStats::class)->summary($owner, 7);

        foreach ($stats['channels'] as $key => $row) {
            if (isset($channels[$key])) {
                $channels[$key]['first_response_min'] = $row['first_response_min'];
                $channels[$key]['ai_replies'] = $row['ai_replies'];
            }
        }

        $out = [
            'generated_at' => $now,
            'counts' => $counts,
            'queue' => $queue,
            'channels' => array_values($channels),
            'alerts' => $this->alerts($owner, $channels, $counts, $sla, $queue),
            'today' => $this->today($owner, $today),
            'week' => $this->week($stats),
            'workload' => ($can['chat'] ?? true) ? $this->workload($owner, $base, $only) : [],
            'sla' => ['enabled' => (bool) $sla->enabled, 'minutes' => $lateAfter],
            'sending' => $this->sending($owner),
        ];
        $out['sales'] = ($can['deals'] ?? false) ? $this->sales($owner, $now) : null;
        $out['tasks'] = ($can['tasks'] ?? false) ? $this->tasks($owner, $now) : null;

        return $out;
    }

    /** The most urgent conversations first: a person requested, late, pending, then the longest waiting. */
    private function queue(\Closure $base, $now, int $lateAfter): array
    {
        $since = "(SELECT MIN(COALESCE(m.message_timestamp, m.created_at)) FROM chat_messages m WHERE m.chat_id = chats.id AND m.from_me = 0 AND m.id > COALESCE((SELECT MAX(a.id) FROM chat_messages a WHERE a.chat_id = chats.id AND a.from_me = 1 AND (a.source IS NULL OR a.source != 'campaign')), 0))";

        $rows = $base()->with(['contact:id,name', 'assignee:id,name', 'instance:id,name,channel'])
            ->select('chats.*')->selectRaw("$since as waiting_since")
            ->where(fn ($w) => $w->whereNotNull('chats.needs_human_at')->orWhereNotNull('chats.pending_at')->orWhere('chats.last_message_from_me', false))
            ->orderByRaw('chats.needs_human_at IS NULL')->orderByRaw('chats.pending_at IS NULL')->orderBy('chats.last_message_at')
            ->limit(60)->get();

        return $rows->map(function (Chat $c) use ($now, $lateAfter) {
            $waitingFor = !$c->last_message_from_me;
            $from = $waitingFor ? ($c->waiting_since ? \Illuminate\Support\Carbon::parse($c->waiting_since) : $c->last_message_at) : ($c->needs_human_at ?: $c->pending_at ?: $c->last_message_at);
            $waited = $from ? max(0, (int) $from->diffInMinutes($now)) : 0;
            $late = $waitingFor && $waited >= $lateAfter;
            $score = ($c->needs_human_at ? 1000 : 0) + ($late ? 500 : 0) + ($c->pending_at ? 200 : 0) + ($waitingFor ? 100 : 0) + min(99, intdiv($waited, 15));

            return [
                'id' => $c->id, 'instance_id' => $c->instance_id, 'name' => $c->displayName(), 'channel' => $c->instance ? $c->instance->channelKey() : 'whatsapp',
                'preview' => (string) $c->last_message_preview, 'human' => (bool) $c->needs_human_at, 'human_reason' => $c->needs_human_reason, 'pending' => (bool) $c->pending_at,
                'waiting' => $waitingFor, 'late' => $late, 'waited' => $waited, 'assignee' => optional($c->assignee)->name, 'unread' => (int) $c->unread_count, 'score' => $score,
            ];
        })->sortByDesc('score')->take(12)->values()->all();
    }

    /** One tile per channel the account uses. */
    private function channels(User $owner, \Closure $base, $today, $now): array
    {
        $tiles = [];
        foreach (Instance::where('user_id', $owner->id)->get(['id', 'name', 'channel', 'status']) as $i) {
            $key = $i->channelKey();
            $tiles[$key] ??= $this->blankTile($key);
            $tiles[$key]['instances']++;
            $i->isConnected() ? $tiles[$key]['connected']++ : $tiles[$key]['down'][] = $i->name ?: $key;
        }

        $channelSql = "COALESCE(instances.channel, 'whatsapp')";
        foreach ((clone $base())->join('instances', 'instances.id', '=', 'chats.instance_id')->where('chats.last_message_from_me', false)
            ->selectRaw("$channelSql as ch, COUNT(*) as n")->groupBy(DB::raw($channelSql))->get() as $r) {
            $tiles[$r->ch] ??= $this->blankTile($r->ch);
            $tiles[$r->ch]['waiting'] = (int) $r->n;
        }
        foreach (DB::table('chat_messages')->join('instances', 'instances.id', '=', 'chat_messages.instance_id')
            ->where('chat_messages.user_id', $owner->id)->where('chat_messages.message_timestamp', '>=', $today)
            ->selectRaw("$channelSql as ch, chat_messages.from_me as me, COUNT(*) as n")->groupBy(DB::raw($channelSql), 'chat_messages.from_me')->get() as $r) {
            $tiles[$r->ch] ??= $this->blankTile($r->ch);
            $tiles[$r->ch][$r->me ? 'out_today' : 'in_today'] = (int) $r->n;
        }

        // WhatsApp first, then the usual order
        $order = array_keys(self::CHANNEL_LABELS);
        uksort($tiles, fn ($a, $b) => array_search($a, $order) <=> array_search($b, $order));

        return $tiles;
    }

    private function blankTile(string $key): array
    {
        [$label, $icon] = self::CHANNEL_LABELS[$key] ?? [ucfirst($key), 'bi-chat'];

        return ['key' => $key, 'label' => $label, 'icon' => $icon, 'instances' => 0, 'connected' => 0, 'down' => [], 'waiting' => 0, 'in_today' => 0, 'out_today' => 0, 'first_response_min' => null, 'ai_replies' => 0];
    }

    private function alerts(User $owner, array $channels, array $counts, SlaSetting $sla, array $queue): array
    {
        $alerts = [];
        foreach ($channels as $t) {
            if ($t['down']) {
                $alerts[] = ['level' => 'danger', 'icon' => 'bi-plug', 'text' => __(':channel is disconnected: :names', ['channel' => $t['label'], 'names' => implode(', ', array_slice($t['down'], 0, 3))]),
                    'url' => $t['key'] === 'whatsapp' ? url('/instances') : (\Illuminate\Support\Facades\Route::has($t['key'] . '.index') ? route($t['key'] . '.index') : url('/instances'))];
            }
        }
        if ($counts['human'] > 0) {
            $alerts[] = ['level' => 'danger', 'icon' => 'bi-headset', 'text' => trans_choice(':n customer asked for a person|:n customers asked for a person', $counts['human'], ['n' => $counts['human']]), 'url' => route('chat.index', ['filter' => 'human'])];
        }
        if ($counts['late'] > 0) {
            $alerts[] = ['level' => 'warning', 'icon' => 'bi-alarm', 'text' => trans_choice(':n conversation is waiting more than :min min|:n conversations are waiting more than :min min', $counts['late'], ['n' => $counts['late'], 'min' => $sla->first_response_minutes]), 'url' => route('chat.index', ['filter' => 'waiting'])];
        }
        $failed = WhatsappJob::where('user_id', $owner->id)->where('updated_at', '>=', now()->subDay())->where(fn ($q) => $q->where('status', 'erro')->orWhere('evolution_status', 'error'))->count();
        if ($failed > 0) {
            $alerts[] = ['level' => 'warning', 'icon' => 'bi-exclamation-triangle', 'text' => trans_choice(':n campaign message failed in the last 24 h|:n campaign messages failed in the last 24 h', $failed, ['n' => $failed]), 'url' => route('campaigns.index')];
        }

        return $alerts;
    }

    private function today(User $owner, $today): array
    {
        $yesterday = $today->copy()->subDay();
        $msgs = fn ($from, $to, $me) => DB::table('chat_messages')->where('user_id', $owner->id)->where('from_me', $me)->where('message_timestamp', '>=', $from)->where('message_timestamp', '<', $to)->count();
        $ai = fn ($from, $to) => DB::table('ai_messages')->join('ai_sessions', 'ai_sessions.id', '=', 'ai_messages.ai_session_id')->where('ai_sessions.user_id', $owner->id)
            ->where('ai_messages.role', 'assistant')->where('ai_messages.status', 'sent')->where('ai_messages.created_at', '>=', $from)->where('ai_messages.created_at', '<', $to)->count();
        $fresh = fn ($from, $to) => Chat::where('user_id', $owner->id)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count();
        $end = $today->copy()->addDay();

        return [
            'in' => ['now' => $msgs($today, $end, 0), 'before' => $msgs($yesterday, $today, 0)],
            'out' => ['now' => $msgs($today, $end, 1), 'before' => $msgs($yesterday, $today, 1)],
            'new' => ['now' => $fresh($today, $end), 'before' => $fresh($yesterday, $today)],
            'ai' => ['now' => $ai($today, $end), 'before' => $ai($yesterday, $today)],
        ];
    }

    /** Messages per day for the last 7 days (received / sent), for the small bar chart. */
    private function week(array $stats): array
    {
        $days = array_slice($stats['daily'], -7, 7, true);
        $rows = [];
        foreach ($days as $date => $perChannel) {
            $rows[] = ['date' => $date, 'total' => array_sum($perChannel)];
        }

        return ['days' => $rows, 'max' => max(1, collect($rows)->max('total') ?: 1)];
    }

    /** Open conversations per person: who is overloaded, what nobody owns. */
    private function workload(User $owner, \Closure $base, ?int $only): array
    {
        $rows = (clone $base())->selectRaw('chats.assigned_to as who, COUNT(*) as open_n, SUM(CASE WHEN chats.last_message_from_me = 0 THEN 1 ELSE 0 END) as waiting_n')
            ->groupBy('chats.assigned_to')->get();
        $names = User::whereIn('id', $rows->pluck('who')->filter())->pluck('name', 'id');

        return $rows->map(fn ($r) => ['name' => $r->who ? ($names[$r->who] ?? __('Team')) : __('Unassigned'), 'open' => (int) $r->open_n, 'waiting' => (int) $r->waiting_n, 'unassigned' => !$r->who])
            ->sortByDesc(fn ($r) => $r['waiting'] * 1000 + $r['open'])->take(6)->values()->all();
    }

    /** Campaign sending health (the old panel numbers), only when the account ever sent a campaign. */
    private function sending(User $owner): ?array
    {
        if (!Schema::hasTable('whatsapp_jobs') || !WhatsappJob::where('user_id', $owner->id)->exists()) {
            return null;
        }

        return ['delivery' => WhatsappJob::getDeliveryRate($owner->id), 'errors' => WhatsappJob::getErrorRate($owner->id)];
    }

    private function sales(User $owner, $now): array
    {
        $open = Deal::where('user_id', $owner->id)->where('status', 'open');
        $closing = (clone $open)->whereNotNull('expected_close_date')->where('expected_close_date', '<=', $now->copy()->addDays(7)->toDateString())->count();

        return [
            'open_count' => (clone $open)->count(),
            'open_value' => (float) (clone $open)->sum('value'),
            'won_count' => Deal::where('user_id', $owner->id)->where('status', 'won')->where('closed_at', '>=', $now->copy()->startOfMonth())->count(),
            'won_value' => (float) Deal::where('user_id', $owner->id)->where('status', 'won')->where('closed_at', '>=', $now->copy()->startOfMonth())->sum('value'),
            'closing_soon' => $closing,
        ];
    }

    private function tasks(User $owner, $now): array
    {
        $open = Task::where('user_id', $owner->id)->whereNull('done_at');
        $list = (clone $open)->whereNotNull('due_at')->where('due_at', '<=', $now->copy()->endOfDay())->orderBy('due_at')->limit(6)->get();

        return [
            'overdue' => (clone $open)->whereNotNull('due_at')->where('due_at', '<', $now)->count(),
            'today' => (clone $open)->whereNotNull('due_at')->whereBetween('due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->count(),
            'list' => $list->map(fn (Task $t) => ['title' => $t->title, 'due' => $t->due_at->format('H:i d/m'), 'overdue' => $t->isOverdue()])->all(),
        ];
    }
}
