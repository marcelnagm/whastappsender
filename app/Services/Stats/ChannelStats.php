<?php

namespace App\Services\Stats;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Numbers per channel (WhatsApp, Telegram, Instagram, TikTok) for one account over the last N days. */
class ChannelStats
{
    public const CHANNELS = ['whatsapp', 'telegram', 'instagram', 'tiktok'];

    /** @return array{since:\Carbon\Carbon,days:int,channels:array,instances:array,daily:array} */
    public function summary(User $user, int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $since = now()->subDays($days)->startOfDay();

        $channelSql = "COALESCE(instances.channel, 'whatsapp')";
        $blank = fn () => ['instances' => 0, 'chats' => 0, 'new_chats' => 0, 'active_chats' => 0, 'waiting' => 0, 'inbound' => 0, 'outbound' => 0, 'ai_replies' => 0, 'first_response_min' => null, 'contacts' => 0, 'linked' => 0];
        $channels = array_fill_keys(self::CHANNELS, null);
        foreach ($channels as $k => $_) {
            $channels[$k] = $blank();
        }

        $instances = DB::table('instances')->where('user_id', $user->id)->get(['id', 'name', 'channel', 'status']);
        $perInstance = [];
        foreach ($instances as $i) {
            $ch = $i->channel ?: 'whatsapp';
            $channels[$ch]['instances']++;
            $perInstance[$i->id] = ['id' => $i->id, 'name' => $i->name, 'channel' => $ch, 'status' => $i->status, 'chats' => 0, 'inbound' => 0, 'outbound' => 0];
        }

        foreach (DB::table('chats')->join('instances', 'instances.id', '=', 'chats.instance_id')->where('chats.user_id', $user->id)
            ->selectRaw("chats.instance_id as iid, $channelSql as ch, COUNT(*) as total,
                SUM(CASE WHEN chats.created_at >= ? THEN 1 ELSE 0 END) as fresh,
                SUM(CASE WHEN chats.last_message_at >= ? THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN chats.last_message_from_me = 0 AND chats.last_message_at IS NOT NULL THEN 1 ELSE 0 END) as waiting", [$since, $since])
            ->groupBy('chats.instance_id', DB::raw($channelSql))->get() as $r) {
            $channels[$r->ch]['chats'] += (int) $r->total;
            $channels[$r->ch]['new_chats'] += (int) $r->fresh;
            $channels[$r->ch]['active_chats'] += (int) $r->active;
            $channels[$r->ch]['waiting'] += (int) $r->waiting;
            if (isset($perInstance[$r->iid])) {
                $perInstance[$r->iid]['chats'] = (int) $r->total;
            }
        }

        foreach (DB::table('chat_messages')->join('instances', 'instances.id', '=', 'chat_messages.instance_id')
            ->where('chat_messages.user_id', $user->id)->where('chat_messages.message_timestamp', '>=', $since)
            ->selectRaw("chat_messages.instance_id as iid, $channelSql as ch, chat_messages.from_me as me, COUNT(*) as n")
            ->groupBy('chat_messages.instance_id', DB::raw($channelSql), 'chat_messages.from_me')->get() as $r) {
            $key = $r->me ? 'outbound' : 'inbound';
            $channels[$r->ch][$key] += (int) $r->n;
            if (isset($perInstance[$r->iid])) {
                $perInstance[$r->iid][$key] += (int) $r->n;
            }
        }

        foreach (DB::table('ai_messages')->join('ai_sessions', 'ai_sessions.id', '=', 'ai_messages.ai_session_id')
            ->join('instances', 'instances.id', '=', 'ai_sessions.instance_id')
            ->where('ai_sessions.user_id', $user->id)->where('ai_messages.role', 'assistant')->where('ai_messages.status', 'sent')
            ->where('ai_messages.created_at', '>=', $since)
            ->selectRaw("$channelSql as ch, COUNT(*) as n")->groupBy(DB::raw($channelSql))->get() as $r) {
            $channels[$r->ch]['ai_replies'] = (int) $r->n;
        }

        foreach (DB::table('contacts')->where('user_id', $user->id)->selectRaw("
            CASE WHEN contact LIKE 'tg:%' THEN 'telegram' WHEN contact LIKE 'ig:%' THEN 'instagram' WHEN contact LIKE 'tt:%' THEN 'tiktok' ELSE 'whatsapp' END as ch,
            COUNT(*) as n, SUM(CASE WHEN linked_contact_id IS NOT NULL THEN 1 ELSE 0 END) as linked")->groupBy('ch')->get() as $r) {
            $channels[$r->ch]['contacts'] = (int) $r->n;
            $channels[$r->ch]['linked'] = (int) $r->linked;
        }

        foreach ($this->firstResponse($user, $since, $channelSql) as $ch => $minutes) {
            $channels[$ch]['first_response_min'] = $minutes;
        }

        return [
            'since' => $since, 'days' => $days, 'channels' => $channels, 'instances' => array_values($perInstance),
            'daily' => $this->daily($user, $since, $days, $channelSql),
        ];
    }

    /** Average minutes between the first message of a conversation started in the period and the first reply. @return array<string,int> */
    private function firstResponse(User $user, $since, string $channelSql): array
    {
        $rows = DB::table('chat_messages')->join('instances', 'instances.id', '=', 'chat_messages.instance_id')
            ->join('chats', 'chats.id', '=', 'chat_messages.chat_id')
            ->where('chat_messages.user_id', $user->id)->where('chats.created_at', '>=', $since)->whereNotNull('chat_messages.message_timestamp')
            ->selectRaw("chat_messages.chat_id as cid, $channelSql as ch,
                MIN(CASE WHEN chat_messages.from_me = 0 THEN chat_messages.message_timestamp END) as first_in,
                MIN(CASE WHEN chat_messages.from_me = 1 THEN chat_messages.message_timestamp END) as first_out")
            ->groupBy('chat_messages.chat_id', DB::raw($channelSql))->limit(2000)->get();

        $acc = [];
        foreach ($rows as $r) {
            if (!$r->first_in || !$r->first_out) {
                continue;
            }
            $diff = (strtotime($r->first_out) - strtotime($r->first_in)) / 60;
            if ($diff >= 0) {
                $acc[$r->ch][] = $diff;
            }
        }

        return array_map(fn ($v) => (int) round(array_sum($v) / count($v)), $acc);
    }

    /** @return array<string,array<string,int>> date => channel => messages (in + out) */
    private function daily(User $user, $since, int $days, string $channelSql): array
    {
        $out = [];
        for ($d = 0; $d < min($days, 30); $d++) {
            $out[now()->subDays($d)->toDateString()] = array_fill_keys(self::CHANNELS, 0);
        }
        $out = array_reverse($out, true);

        $from = now()->subDays(min($days, 30) - 1)->startOfDay();
        foreach (DB::table('chat_messages')->join('instances', 'instances.id', '=', 'chat_messages.instance_id')
            ->where('chat_messages.user_id', $user->id)->where('chat_messages.message_timestamp', '>=', $from)
            ->selectRaw("DATE(chat_messages.message_timestamp) as d, $channelSql as ch, COUNT(*) as n")
            ->groupBy(DB::raw('DATE(chat_messages.message_timestamp)'), DB::raw($channelSql))->get() as $r) {
            if (isset($out[$r->d])) {
                $out[$r->d][$r->ch] = (int) $r->n;
            }
        }

        return $out;
    }
}
