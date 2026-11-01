<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deactivates WhatsApp contacts that never receive our messages, so campaigns stop wasting time on them.
 *
 * "Undelivered" = WhatsApp accepted the message (status "sent") but no delivery/read receipt arrived within
 * contacts.undelivered.after_hours. A contact is deactivated (status "inativo", reason "undelivered") when it has at least
 * min_messages such messages AND shows no sign of life afterwards (no delivered/read message, nothing written by the contact).
 * Guard: accounts whose receipts are not arriving at all are skipped, otherwise every message would look undelivered.
 */
class UnresponsiveContacts
{
    public const REASON = 'undelivered';
    private const UNDELIVERED = ['sent', 'server_ack', 'pending'];
    public const DELIVERED = ['delivered', 'delivery_ack', 'read', 'played'];

    /** @return array{checked:int,deactivated:int[],skipped_accounts:int[]} */
    public function run(?int $hours = null, bool $dryRun = false, ?int $userId = null): array
    {
        $cfg = config('contacts.undelivered');
        $result = ['checked' => 0, 'deactivated' => [], 'skipped_accounts' => []];
        if (!$cfg['enabled']) {
            return $result;
        }

        $hours = max(1, $hours ?? (int) $cfg['after_hours']);
        $cut = now()->subHours($hours);
        $floor = now()->subDays((int) $cfg['lookback_days']);

        $stale = $this->staleByContact($cut, $floor);
        $stale = array_filter($stale, fn ($s) => $s['n'] >= (int) $cfg['min_messages']);
        if ($userId !== null) {
            $mine = Contact::whereIn('id', array_keys($stale))->where('user_id', $userId)->pluck('id')->all();
            $stale = array_intersect_key($stale, array_flip($mine));
        }
        $result['checked'] = count($stale);
        if (!$stale) {
            return $result;
        }

        $evidence = [];
        foreach (Contact::whereIn('id', array_keys($stale))->where('status', 'ativo')->get() as $contact) {
            if (!$this->isWhatsappNumber($contact)) {
                continue;
            }
            $uid = (int) $contact->user_id;
            $evidence[$uid] ??= $this->receiptsArrive($uid);
            if (!$evidence[$uid]) {
                $result['skipped_accounts'][$uid] = $uid;
                continue;
            }
            if ($this->hasSignOfLife($contact, $stale[$contact->id]['first_at'])) {
                continue;
            }

            if (!$dryRun) {
                $contact->update(['status' => 'inativo', 'deactivated_reason' => self::REASON, 'deactivated_at' => now()]);
                Log::info("Contact {$contact->id} deactivated: {$stale[$contact->id]['n']} message(s) undelivered for {$hours}h.");
            }
            $result['deactivated'][] = $contact->id;
        }
        $result['skipped_accounts'] = array_values($result['skipped_accounts']);

        return $result;
    }

    /** @return array<int,array{n:int,first_at:string}> contact id => stale message count and time of the oldest */
    private function staleByContact($cut, $floor): array
    {
        $out = [];
        $add = function ($rows) use (&$out) {
            foreach ($rows as $r) {
                $o = $out[$r->cid] ?? ['n' => 0, 'first_at' => $r->first_at];
                $o['n'] += (int) $r->n;
                $o['first_at'] = min($o['first_at'], $r->first_at);
                $out[$r->cid] = $o;
            }
        };

        // campaign / AI messages tracked by their job row
        $add(DB::table('whatsapp_job')
            ->where('status', 'processado')->whereNotNull('contact_id')->whereNotNull('message_id')
            ->where(fn ($w) => $w->whereNull('evolution_status')->orWhereIn(DB::raw('LOWER(evolution_status)'), self::UNDELIVERED))
            ->where('updated_at', '<=', $cut)->where('updated_at', '>=', $floor)
            ->selectRaw('contact_id as cid, COUNT(*) as n, MIN(updated_at) as first_at')->groupBy('contact_id')->get());

        // messages sent from the chat (the ones that already have a job row are not counted twice)
        $add(DB::table('chat_messages')
            ->join('chats', 'chats.id', '=', 'chat_messages.chat_id')->join('instances', 'instances.id', '=', 'chat_messages.instance_id')
            ->where('instances.channel', 'whatsapp')->whereNotNull('chats.contact_id')
            ->where('chat_messages.from_me', true)->where('chat_messages.status', 'sent')->whereNotNull('chat_messages.message_id')
            ->where('chat_messages.message_timestamp', '<=', $cut)->where('chat_messages.message_timestamp', '>=', $floor)
            ->whereNotIn('chat_messages.message_id', fn ($q) => $q->select('message_id')->from('whatsapp_job')->whereNotNull('message_id'))
            ->selectRaw('chats.contact_id as cid, COUNT(*) as n, MIN(chat_messages.message_timestamp) as first_at')->groupBy('chats.contact_id')->get());

        return $out;
    }

    private function isWhatsappNumber(Contact $c): bool
    {
        return !preg_match('/^(tg|ig|tt):/', (string) $c->contact) && $c->contact !== '';
    }

    /** True when delivery receipts of this account are really coming in. */
    private function receiptsArrive(int $userId): bool
    {
        $since = now()->subDays((int) config('contacts.undelivered.evidence_days'));

        return DB::table('whatsapp_job')->where('user_id', $userId)->whereIn(DB::raw('LOWER(evolution_status)'), self::DELIVERED)->where('updated_at', '>=', $since)->exists()
            || DB::table('chat_messages')->where('user_id', $userId)->where('from_me', true)->whereIn('status', ['delivered', 'read'])->where('message_timestamp', '>=', $since)->exists();
    }

    private function hasSignOfLife(Contact $c, string $since): bool
    {
        return DB::table('whatsapp_job')->where('contact_id', $c->id)->whereIn(DB::raw('LOWER(evolution_status)'), self::DELIVERED)->where('updated_at', '>=', $since)->exists()
            || DB::table('chat_messages')->join('chats', 'chats.id', '=', 'chat_messages.chat_id')->where('chats.contact_id', $c->id)
                ->where('chat_messages.message_timestamp', '>=', $since)
                ->where(fn ($w) => $w->where('chat_messages.from_me', false)->orWhereIn('chat_messages.status', ['delivered', 'read']))->exists();
    }

    /** Puts a contact back to "ativo" when it was deactivated for being unreachable and now shows life. */
    public static function reactivate(int $contactId): void
    {
        if (!config('contacts.undelivered.reactivate')) {
            return;
        }

        Contact::where('id', $contactId)->where('status', 'inativo')->where('deactivated_reason', self::REASON)
            ->update(['status' => 'ativo', 'deactivated_reason' => null, 'deactivated_at' => null]);
    }
}
