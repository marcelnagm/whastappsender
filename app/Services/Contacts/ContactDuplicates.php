<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/** Finds people registered more than once: same e-mail, or same phone number written differently (country code, 9th digit, punctuation). */
class ContactDuplicates
{
    private const SCAN_LIMIT = 50000;

    /** Comparable key of a phone: no punctuation, no country code 55, DDD + last 8 digits (so with / without the extra 9 match). null when it is not a phone. */
    public static function phoneKey(?string $contact): ?string
    {
        $raw = (string) $contact;
        if ($raw === '' || preg_match('/^[a-z]{2}:/i', $raw) || str_contains($raw, '@')) {
            return null;                      // channel contacts (tg:, ig:...) are never phones
        }
        $d = preg_replace('/\D+/', '', $raw);
        if (strlen($d) < 10) {
            return null;
        }
        if (in_array(strlen($d), [12, 13], true) && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }

        return strlen($d) >= 10 && strlen($d) <= 11 ? substr($d, 0, 2) . substr($d, -8) : $d;
    }

    /**
     * @return array<int,array{key:string,reason:string,contacts:\Illuminate\Support\Collection}> groups of 2+ contacts, ignored ones left out
     */
    public function groups(int $userId, int $limit = 100): array
    {
        $ignored = DB::table('duplicate_ignores')->where('user_id', $userId)->pluck('group_key')->flip();
        $groups = [];

        // e-mail
        $mails = Contact::where('user_id', $userId)->whereNotNull('email')->where('email', '!=', '')
            ->selectRaw('lower(trim(email)) as e, count(*) as n')->groupBy(DB::raw('lower(trim(email))'))->having('n', '>', 1)->limit($limit * 2)->pluck('e');
        foreach ($mails as $e) {
            $groups['email:' . $e] = ['reason' => 'email', 'ids' => Contact::where('user_id', $userId)->whereRaw('lower(trim(email)) = ?', [$e])->pluck('id')->all()];
        }

        // phone
        $byPhone = [];
        foreach (Contact::where('user_id', $userId)->whereNotNull('contact')->orderBy('id')->limit(self::SCAN_LIMIT)->get(['id', 'contact']) as $c) {
            if ($k = self::phoneKey($c->contact)) {
                $byPhone[$k][] = $c->id;
            }
        }
        foreach ($byPhone as $k => $ids) {
            if (count($ids) > 1) {
                $groups['phone:' . $k] = ['reason' => 'phone', 'ids' => $ids];
            }
        }

        // a person found by both e-mail and phone is one group
        $out = [];
        foreach ($groups as $key => $g) {
            if (isset($ignored[$key])) {
                continue;
            }
            $merged = false;
            foreach ($out as &$o) {
                if (array_intersect($o['ids'], $g['ids'])) {
                    $o['ids'] = array_values(array_unique(array_merge($o['ids'], $g['ids'])));
                    $o['reason'] = 'both';
                    $o['keys'][] = $key;
                    $merged = true;
                    break;
                }
            }
            unset($o);
            if (!$merged) {
                $out[] = ['key' => $key, 'keys' => [$key], 'reason' => $g['reason'], 'ids' => $g['ids']];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_map(function ($g) use ($userId) {
            $contacts = Contact::where('user_id', $userId)->whereIn('id', $g['ids'])->orderBy('id')->get();
            // the chats, deals and tasks tell which record is the "real" one
            foreach ($contacts as $c) {
                $c->setAttribute('chats_n', DB::table('chats')->where('contact_id', $c->id)->count());
                $c->setAttribute('deals_n', DB::table('deals')->where('contact_id', $c->id)->count());
            }

            return ['key' => $g['key'], 'keys' => $g['keys'], 'reason' => $g['reason'], 'contacts' => $contacts];
        }, $out);
    }

    public function ignore(int $userId, array $keys): void
    {
        foreach ($keys as $k) {
            DB::table('duplicate_ignores')->updateOrInsert(['user_id' => $userId, 'group_key' => mb_substr((string) $k, 0, 120)], ['created_at' => now(), 'updated_at' => now()]);
        }
    }
}
