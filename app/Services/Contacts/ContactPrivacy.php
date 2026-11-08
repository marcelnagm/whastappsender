<?php

namespace App\Services\Contacts;

use App\Models\Activity;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data-subject requests (LGPD/GDPR): export everything kept about a person, or erase it. Erasing keeps the
 * business skeleton (deals, values, the consent history that proves the opt-out) but removes everything that
 * identifies the person or what was said, and blocks any further message to them.
 */
class ContactPrivacy
{
    public const ERASED = 'erased';

    /** Everything kept about the person, ready to hand over. */
    public function export(Contact $contact): array
    {
        $uid = (int) $contact->user_id;
        $chats = Chat::where('user_id', $uid)->where('contact_id', $contact->id)->get();

        return [
            'generated_at' => now()->toIso8601String(),
            'contact' => $contact->only(['id', 'name', 'contact', 'email', 'birthday', 'status', 'lifecycle_stage', 'custom', 'created_at']),
            'conversations' => $chats->map(fn (Chat $c) => [
                'channel_id' => $c->remote_jid, 'name' => $c->name, 'phone' => $c->phone,
                'messages' => ChatMessage::where('chat_id', $c->id)->orderBy('id')->get(['from_me', 'type', 'body', 'created_at'])->map(fn ($m) => [
                    'direction' => $m->from_me ? 'sent' : 'received', 'type' => $m->type, 'text' => $m->body, 'at' => optional($m->created_at)->toIso8601String(),
                ])->all(),
            ])->all(),
            'deals' => Deal::where('user_id', $uid)->where('contact_id', $contact->id)->get(['title', 'value', 'status', 'origin', 'created_at', 'closed_at'])->toArray(),
            'tasks' => Task::where('user_id', $uid)->where('contact_id', $contact->id)->get(['title', 'notes', 'due_at', 'done_at'])->toArray(),
            'activities' => Activity::where('user_id', $uid)->where('contact_id', $contact->id)->get(['type', 'outcome', 'notes', 'duration_min', 'occurred_at'])->toArray(),
            'consent' => DB::table('consent_logs')->where('user_id', $uid)->where('contact_id', $contact->id)->get(['action', 'source', 'created_at'])->toArray(),
        ];
    }

    /** @return array{messages:int,chats:int} how much was wiped */
    public function erase(Contact $contact): array
    {
        $uid = (int) $contact->user_id;
        $stats = ['messages' => 0, 'chats' => 0];

        DB::transaction(function () use ($contact, $uid, &$stats) {
            $chats = Chat::where('user_id', $uid)->where('contact_id', $contact->id)->get();
            foreach ($chats as $chat) {
                ChatMessage::where('chat_id', $chat->id)->whereNotNull('media_path')->pluck('media_path')->each(function ($p) {
                    try {
                        Storage::delete($p);
                    } catch (\Throwable $e) {
                        // a missing file is already what we want
                    }
                });
                $stats['messages'] += ChatMessage::where('chat_id', $chat->id)->toBase()->update([
                    'body' => null, 'media_path' => null, 'file_name' => null, 'quoted_preview' => null, 'reactions' => null,
                ]);
                ChatNote::where('chat_id', $chat->id)->delete();
                // the channel address is anonymised too (unique per instance, so it keeps the chat id)
                $chat->forceFill(['name' => null, 'phone' => null, 'remote_jid' => 'erased-' . $chat->id, 'avatar_url' => null, 'avatar_path' => null,
                    'last_message_preview' => null])->save();
                $stats['chats']++;
            }
            Task::where('user_id', $uid)->where('contact_id', $contact->id)->update(['notes' => null]);
            Activity::where('user_id', $uid)->where('contact_id', $contact->id)->update(['notes' => null, 'outcome' => null]);

            Contact::whereKey($contact->id)->toBase()->update([
                'name' => __('Erased contact') . ' #' . $contact->id, 'contact' => 'erased-' . $contact->id, 'email' => null, 'lid' => null,
                'profile_url' => null, 'custom' => null, 'birthday' => null, 'company_id' => null, 'ignore_me' => 1,
                'deactivated_reason' => self::ERASED, 'deactivated_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('consent_logs')->insert(['user_id' => $uid, 'contact_id' => $contact->id, 'action' => 'revoked', 'source' => 'data erasure', 'created_at' => now()]);
        });

        return $stats;
    }
}
