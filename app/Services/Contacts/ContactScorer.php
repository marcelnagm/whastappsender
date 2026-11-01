<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Automatic engagement score (0-100) and ranking tier of a contact.
 *
 * Signals (max points): recency of the last message FROM the person (30), how much they wrote lately (20), how often they answered us
 * (20), delivery/read receipts of what we sent (15), channels linked to the same person (5), profile completeness (5, +5 for an actual two-way
 * conversation). Penalties: messages that never got delivered, a person who opted out, a number without WhatsApp.
 * Every signal looks at ALL channels of the person (WhatsApp + linked Telegram / Instagram / TikTok contacts).
 */
class ContactScorer
{
    public static function tier(int $score): string
    {
        $t = config('contacts.score.tiers');

        return $score >= $t['hot'] ? 'hot' : ($score >= $t['warm'] ? 'warm' : ($score >= $t['cold'] ? 'cold' : 'frozen'));
    }

    /** @return array{score:int,tier:string,breakdown:array<string,int|float>} without saving */
    public function calculate(Contact $contact): array
    {
        $days = (int) config('contacts.score.window_days');
        $since = now()->subDays($days);
        $ids = \App\Services\Channels\ContactLinks::group($contact)->pluck('id')->all();

        $b = ['recency' => 0, 'frequency' => 0, 'responsiveness' => 0, 'receipts' => 0, 'channels' => 0, 'profile' => 0, 'conversation' => 0, 'penalties' => 0];

        // ---- what the person wrote ---------------------------------------------------------------------------------
        $in = DB::table('chat_messages')->join('chats', 'chats.id', '=', 'chat_messages.chat_id')
            ->whereIn('chats.contact_id', $ids)->where('chat_messages.from_me', false)
            ->selectRaw('MAX(chat_messages.message_timestamp) as last_at, SUM(CASE WHEN chat_messages.message_timestamp >= ? THEN 1 ELSE 0 END) as recent', [$since])->first();

        if ($in && $in->last_at) {
            $age = max(0, (now()->timestamp - strtotime($in->last_at)) / 86400);
            // smooth decay: 30 points today, half after ~14 days, close to zero after the window
            $b['recency'] = (int) round(30 * max(0, 1 - $age / max(1, $days)) ** 2);
            $b['frequency'] = (int) min(20, round(6 * log(1 + (int) $in->recent, 2))); // 1 msg ~6, 3 ~12, 7 ~18
        }

        // ---- how often they answered us -----------------------------------------------------------------------------
        $msgs = DB::table('chat_messages')->join('chats', 'chats.id', '=', 'chat_messages.chat_id')
            ->whereIn('chats.contact_id', $ids)->where('chat_messages.message_timestamp', '>=', $since)
            ->orderByDesc('chat_messages.message_timestamp')->limit(400)
            ->get(['chat_messages.chat_id', 'chat_messages.from_me', 'chat_messages.message_timestamp'])->sortBy('message_timestamp')->values();

        $sent = 0;
        $answered = 0;
        $open = []; // chat id => time of our last unanswered message
        foreach ($msgs as $m) {
            $ts = strtotime($m->message_timestamp);
            if ($m->from_me) {
                $sent++;
                $open[$m->chat_id] ??= $ts;
            } elseif (isset($open[$m->chat_id])) {
                if ($ts - $open[$m->chat_id] <= 86400 * 2) {
                    $answered++;
                }
                unset($open[$m->chat_id]);
            }
        }
        if ($sent > 0) {
            $b['responsiveness'] = (int) round(20 * min(1, $answered / $sent) * min(1, 0.5 + $sent / 6));
        }
        if ($answered > 0) {
            $b['conversation'] = 5;
        }

        // ---- receipts of what we sent (campaigns / AI) --------------------------------------------------------------
        $jobs = DB::table('whatsapp_job')->whereIn('contact_id', $ids)->where('status', 'processado')->where('updated_at', '>=', $since)
            ->selectRaw("COUNT(*) as n,
                SUM(CASE WHEN LOWER(evolution_status) IN ('delivered','delivery_ack','read','played') THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN LOWER(evolution_status) IN ('read','played') THEN 1 ELSE 0 END) as read_n")->first();
        $stale = 0;
        if ($jobs && (int) $jobs->n > 0) {
            $n = (int) $jobs->n;
            $b['receipts'] = (int) round(7 * ($jobs->delivered / $n) + 8 * ($jobs->read_n / $n));
            $stale = $n - (int) $jobs->delivered;
        }

        // ---- the person across channels and profile ------------------------------------------------------------------
        $b['channels'] = (int) min(5, round((count($ids) - 1) * 2.5));
        $b['profile'] = ($contact->name ? 3 : 0) + ($contact->email ? 2 : 0);

        // ---- penalties ---------------------------------------------------------------------------------------------------
        $penalty = 0;
        if ($contact->status === 'no-whatsapp') {
            $penalty = 100;
        } elseif ($contact->ignore_me) {
            $penalty = 100;
        } elseif ($contact->deactivated_reason === UnresponsiveContacts::REASON) {
            $penalty = 40;
        } else {
            $penalty = min(25, $stale * 8);
        }
        $b['penalties'] = -$penalty;

        $score = (int) max(0, min(100, array_sum($b)));

        return ['score' => $score, 'tier' => self::tier($score), 'breakdown' => $b];
    }

    /** Calculates and stores. Contacts with a manually typed score are left untouched. */
    public function recalculate(Contact $contact): ?array
    {
        if ($contact->score_locked) {
            return null;
        }

        $r = $this->calculate($contact);
        // query-builder update: does not fire the model event that locks manual scores
        Contact::where('id', $contact->id)->update([
            'score' => $r['score'], 'score_breakdown' => json_encode($r['breakdown']), 'score_updated_at' => now(),
        ]);
        $contact->score = $r['score'];
        $contact->score_breakdown = $r['breakdown'];

        return $r;
    }

    /**
     * Recalculates what is stale: never scored, older than refresh_hours, or with activity since the last calculation.
     *
     * @return int number of contacts updated
     */
    public function refreshDue(?int $userId = null, int $limit = 2000, bool $all = false): int
    {
        if (!config('contacts.score.enabled')) {
            return 0;
        }

        $refresh = now()->subHours((int) config('contacts.score.refresh_hours'));
        $query = Contact::where('score_locked', false)->when($userId, fn ($q) => $q->where('user_id', $userId));
        if (!$all) {
            $query->where(function ($q) use ($refresh) {
                $q->whereNull('score_updated_at')->orWhere('score_updated_at', '<', $refresh)
                    ->orWhereExists(fn ($e) => $e->select(DB::raw(1))->from('chats')->join('chat_messages', 'chat_messages.chat_id', '=', 'chats.id')
                        ->whereColumn('chats.contact_id', 'contacts.id')->where('chat_messages.from_me', false)
                        ->whereColumn('chat_messages.created_at', '>', 'contacts.score_updated_at'));
            });
        }

        $n = 0;
        $query->orderByRaw('score_updated_at IS NOT NULL')->orderBy('score_updated_at')->limit($limit)->get()->each(function (Contact $c) use (&$n) {
            $this->recalculate($c);
            $n++;
        });

        return $n;
    }
}
