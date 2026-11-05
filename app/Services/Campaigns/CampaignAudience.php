<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use App\Services\Channels\ReplyWindow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Who receives a campaign message: contacts chosen by score (tiers and/or a score range) and by channel.
 *
 *  - whatsapp  : active WhatsApp numbers (what campaigns always sent to)
 *  - telegram / instagram / facebook / tiktok : contacts of that channel that can be answered right now (the customer wrote
 *    to the bot/account and, for Instagram / Facebook / TikTok, is still inside the reply window). These channels cannot
 *    start conversations, so nobody outside the window is ever included.
 *
 * A person linked on several channels is reached once: when WhatsApp is selected the linked channel contacts are left out.
 */
class CampaignAudience
{
    public const CHANNELS = ['whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok'];
    public const TIERS = ['hot', 'warm', 'cold', 'frozen'];

    /** @return array{tiers:string[],min_score:?int,max_score:?int,channels:string[]} */
    public static function normalize(?array $in): array
    {
        $in ??= [];
        $channels = array_values(array_intersect(self::CHANNELS, array_map('strval', (array) ($in['channels'] ?? ['whatsapp']))));
        $min = isset($in['min_score']) && $in['min_score'] !== '' ? max(0, min(100, (int) $in['min_score'])) : null;
        $max = isset($in['max_score']) && $in['max_score'] !== '' ? max(0, min(100, (int) $in['max_score'])) : null;
        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [
            'tiers' => array_values(array_intersect(self::TIERS, array_map('strval', (array) ($in['tiers'] ?? [])))),
            'min_score' => $min,
            'max_score' => $max,
            'channels' => $channels ?: ['whatsapp'],
        ];
    }

    /** Contacts of one channel that match the score filters (and can be reached now). */
    public function query(int $userId, array $audience, string $channel): Builder
    {
        $a = self::normalize($audience);

        // ignore_me: null (or 0) = may be messaged; 1 = opted out
        $q = Contact::query()->where('user_id', $userId)->withConsent($userId)->where(fn ($w) => $w->whereNull('ignore_me')->orWhere('ignore_me', 0));

        $q->where('status', $channel === 'whatsapp' ? 'ativo' : $channel);

        if ($a['tiers']) {
            $q->where(function ($w) use ($a) {
                foreach ($a['tiers'] as $tier) {
                    $w->orWhere(fn ($t) => $t->tier($tier));
                }
            });
        }
        if ($a['min_score'] !== null) {
            $q->where('score', '>=', $a['min_score']);
        }
        if ($a['max_score'] !== null) {
            $q->where('score', '<=', $a['max_score']);
        }

        if ($channel !== 'whatsapp') {
            $this->onlyReachableNow($q, $channel);

            if (in_array('whatsapp', $a['channels'], true)) {
                $wa = $this->query($userId, $audience, 'whatsapp')->select('contacts.id');
                $q->where(fn ($w) => $w->whereNull('contacts.linked_contact_id')->orWhereNotIn('contacts.linked_contact_id', $wa));
            }
        }

        return $q;
    }

    /** @return array{total:int,by_channel:array<string,int>} */
    public function count(int $userId, array $audience): array
    {
        $a = self::normalize($audience);
        $by = [];
        foreach ($a['channels'] as $ch) {
            $by[$ch] = (int) $this->query($userId, $a, $ch)->count();
        }

        return ['total' => array_sum($by), 'by_channel' => $by];
    }

    /** True when the audience reaches anyone through a channel other than WhatsApp. */
    public static function usesOtherChannels(?array $audience): bool
    {
        return (bool) array_diff(self::normalize($audience)['channels'], ['whatsapp']);
    }

    private function onlyReachableNow(Builder $q, string $channel): void
    {
        $hours = ReplyWindow::hours($channel);

        $q->whereExists(function ($e) use ($channel, $hours) {
            $e->select(DB::raw(1))->from('chats')
                ->join('instances', 'instances.id', '=', 'chats.instance_id')
                ->whereColumn('chats.contact_id', 'contacts.id')
                ->where('instances.channel', $channel);

            if ($hours !== null) {
                $since = now()->subHours($hours)->toDateTimeString();
                $e->whereExists(fn ($m) => $m->select(DB::raw(1))->from('chat_messages')
                    ->whereColumn('chat_messages.chat_id', 'chats.id')
                    ->where('chat_messages.from_me', false)
                    ->where('chat_messages.message_timestamp', '>', $since));
            }
        });
    }
}
