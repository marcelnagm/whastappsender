<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Services\Channels\ContactLinks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Folds a duplicate contact into the one that stays: conversations, deals, tasks, consent proof and forms move over, empty fields are filled. */
class ContactMerge
{
    /** Tables where a row points to a contact with `contact_id`. */
    private const TABLES = ['chats', 'deals', 'tasks', 'consent_logs', 'contact_form_submissions', 'whatsapp_job', 'ai_sessions'];

    public function merge(Contact $keep, Contact $drop, ?int $by = null): Contact
    {
        abort_if($keep->id === $drop->id, 422, __('Choose two different contacts.'));
        abort_if((int) $keep->user_id !== (int) $drop->user_id, 403);
        abort_if(ContactLinks::isChannelContact($keep) !== ContactLinks::isChannelContact($drop), 422, __('A WhatsApp contact and a channel contact are linked, not merged.'));

        return DB::transaction(function () use ($keep, $drop, $by) {
            $snapshot = $drop->toArray();

            foreach (self::TABLES as $t) {
                if (Schema::hasTable($t) && Schema::hasColumn($t, 'contact_id')) {
                    DB::table($t)->where('contact_id', $drop->id)->update(['contact_id' => $keep->id]);
                }
            }
            // channel contacts that pointed at the dropped WhatsApp contact now point at the one that stays
            Contact::where('user_id', $keep->user_id)->where('linked_contact_id', $drop->id)->update(['linked_contact_id' => $keep->id]);
            if ($keep->linked_contact_id === $drop->id) {
                $keep->linked_contact_id = null;
            }

            $this->fill($keep, $drop);
            $keep->save();

            DB::table('contact_merges')->insert(['user_id' => $keep->user_id, 'kept_id' => $keep->id, 'dropped_id' => $drop->id, 'snapshot' => json_encode($snapshot), 'merged_by' => $by, 'created_at' => now()]);
            $drop->delete();

            return $keep->fresh();
        });
    }

    /** The kept record wins; gaps are filled from the dropped one. Opt-out always survives. */
    private function fill(Contact $keep, Contact $drop): void
    {
        if (trim((string) $keep->name) === '' || ctype_digit(preg_replace('/\D/', '', (string) $keep->name)) && preg_replace('/\D/', '', (string) $keep->name) === trim((string) $keep->name)) {
            if (trim((string) $drop->name) !== '') {
                $keep->name = $drop->name;
            }
        }
        foreach (['email', 'profile_url', 'lid'] as $f) {
            if (trim((string) $keep->{$f}) === '' && trim((string) $drop->{$f}) !== '') {
                $keep->{$f} = $drop->{$f};
            }
        }
        if (!$keep->score_locked) {
            $keep->score = max((int) $keep->score, (int) $drop->score);
        }
        $keep->ignore_me = (bool) $keep->ignore_me || (bool) $drop->ignore_me;
        $keep->custom = ($keep->custom || $drop->custom) ? array_merge((array) $drop->custom, (array) $keep->custom) : null;

        // consent: if either person asked not to be messaged, that stands; otherwise any proof of opt-in is kept
        if ($keep->consent_status === 'revoked' || $drop->consent_status === 'revoked') {
            $src = $keep->consent_status === 'revoked' ? $keep : $drop;
            $keep->forceFill(['consent_status' => 'revoked', 'consent_source' => $src->consent_source, 'consent_revoked_at' => $src->consent_revoked_at, 'consent_at' => $keep->consent_at ?? $drop->consent_at]);
        } elseif ($keep->consent_status !== 'granted' && $drop->consent_status === 'granted') {
            $keep->forceFill(['consent_status' => 'granted', 'consent_source' => $drop->consent_source, 'consent_at' => $drop->consent_at]);
        }

        // a contact that is "deactivated" only stays so when both are
        if ($keep->status !== 'ativo' && $drop->status === 'ativo') {
            $keep->status = 'ativo';
            $keep->deactivated_reason = null;
            $keep->deactivated_at = null;
        }
    }
}
