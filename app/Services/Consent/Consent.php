<?php

namespace App\Services\Consent;

use App\Models\Contact;
use App\Services\Channels\ContactLinks;
use Illuminate\Support\Facades\DB;

/** Records who agreed (or stopped agreeing) to receive messages, with the proof, and keeps the sending lists in line with it. */
class Consent
{
    public const SOURCES = ['form', 'keyword', 'link', 'manual', 'import', 'api'];

    /** "optout" | "optin" | null for a message that is exactly one of the configured words or phrases (case, accents and punctuation ignored). */
    public static function keyword(?string $text): ?string
    {
        $raw = mb_strtolower(trim((string) $text));
        if ($raw === '') {
            return null;
        }
        $optout = (array) config('consent.optout_keywords');
        $optin = (array) config('consent.optin_keywords');
        if (in_array($raw, $optout, true)) {
            return 'optout';
        }
        if (in_array($raw, $optin, true)) {
            return 'optin';
        }

        // "Parar!", "  STOP. ", "Quero sair da lista": same words with the noise removed
        $n = self::normalize($raw);
        if ($n === '') {
            return null;
        }
        foreach (array_merge($optout, (array) config('consent.optout_phrases')) as $w) {
            if (self::normalize($w) === $n) {
                return 'optout';
            }
        }
        foreach ($optin as $w) {
            if (self::normalize($w) === $n) {
                return 'optin';
            }
        }

        return null;
    }

    private static function normalize(string $s): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', '', strtolower(\Illuminate\Support\Str::ascii($s)))));
    }

    /** Applies the keyword (if it is one) to the contact. @return string|null the action taken: "revoked" | "granted" */
    public function handleKeyword(Contact $contact, ?string $text): ?string
    {
        $k = self::keyword($text);
        if ($k === 'optout') {
            $this->revoke($contact, 'keyword', trim((string) $text));

            return 'revoked';
        }
        if ($k === 'optin') {
            $this->grant($contact, 'keyword', trim((string) $text));

            return 'granted';
        }

        return null;
    }

    public function grant(Contact $contact, string $source, ?string $evidence = null, ?string $ip = null, ?int $actorId = null): void
    {
        if ($contact->consent_status === 'granted' && (int) $contact->ignore_me !== 1) {
            return; // already on record
        }
        $contact->forceFill(['consent_status' => 'granted', 'consent_source' => $source, 'consent_at' => now(), 'consent_revoked_at' => null, 'ignore_me' => null])->save();
        $this->log($contact, 'granted', $source, $evidence, $ip, $actorId);
    }

    /** The person is out for good: also every contact of the same person on other channels (they are the same human). */
    public function revoke(Contact $contact, string $source, ?string $evidence = null, ?string $ip = null, ?int $actorId = null): void
    {
        foreach (ContactLinks::group($contact)->push($contact)->unique('id') as $c) {
            if ($c->consent_status === 'revoked' && (int) $c->ignore_me === 1) {
                continue;
            }
            $c->forceFill(['consent_status' => 'revoked', 'consent_source' => $source, 'consent_revoked_at' => now(), 'ignore_me' => 1])->save();
            $this->log($c, 'revoked', $source, $evidence, $ip, $actorId);
        }
    }

    public function log(Contact $contact, string $action, string $source, ?string $evidence, ?string $ip = null, ?int $actorId = null): void
    {
        DB::table('consent_logs')->insert([
            'user_id' => $contact->user_id, 'contact_id' => $contact->id, 'action' => $action, 'source' => mb_substr($source, 0, 40),
            'evidence' => $evidence !== null ? mb_substr($evidence, 0, 1000) : null, 'ip' => $ip, 'actor_id' => $actorId, 'created_at' => now(),
        ]);
    }
}
