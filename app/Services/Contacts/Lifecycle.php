<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Where each person is in the relationship: lead -> qualified -> opportunity -> customer (or churned).
 * Deals move people forward on their own (a deal makes an opportunity, a won deal a customer); the automatic
 * path never moves anyone backwards, only a person can (manual set / bulk action).
 */
class Lifecycle
{
    public const STAGES = ['lead', 'qualified', 'opportunity', 'customer', 'churned'];

    public static function labels(): array
    {
        return ['lead' => __('Lead'), 'qualified' => __('Qualified'), 'opportunity' => __('Opportunity'), 'customer' => __('Customer'), 'churned' => __('Churned')];
    }

    /** Automatic move: only forward, never out of "churned" back by itself except to customer through a new won deal. */
    public function advance(Contact $contact, string $to): bool
    {
        $rank = array_flip(self::STAGES);
        $current = $contact->lifecycle_stage;
        if (!isset($rank[$to])) {
            return false;
        }
        $from = $current !== null ? $rank[$current] : -1;
        $allowed = $to === 'customer' && $current === 'churned' ? true : ($to !== 'churned' && $rank[$to] > $from);

        return $allowed ? $this->write($contact, $to) : false;
    }

    /** A person decides. */
    public function set(Contact $contact, ?string $to): bool
    {
        if ($to !== null && !in_array($to, self::STAGES, true)) {
            return false;
        }

        return $contact->lifecycle_stage === $to ? false : $this->write($contact, $to);
    }

    private function write(Contact $contact, ?string $to): bool
    {
        // query-builder write: no model events, the contact "updated_at" is left alone
        Contact::whereKey($contact->id)->toBase()->update(['lifecycle_stage' => $to, 'lifecycle_changed_at' => now()]);
        $contact->forceFill(['lifecycle_stage' => $to, 'lifecycle_changed_at' => now()])->syncOriginal();

        return true;
    }

    /** Called when a deal is saved. */
    public function onDeal(Deal $deal): void
    {
        if (!$deal->contact_id) {
            return;
        }
        $contact = Contact::where('user_id', $deal->user_id)->find($deal->contact_id);
        if (!$contact) {
            return;
        }
        if ($deal->status === 'won') {
            $this->advance($contact, 'customer');
        } elseif ($deal->status === 'open') {
            $this->advance($contact, 'opportunity');
        }
    }

    /** @return array<string,int> contacts per stage (null stage counted as "lead") */
    public function counts(User $owner): array
    {
        $rows = Contact::where('user_id', $owner->id)->select('lifecycle_stage', DB::raw('count(*) as n'))->groupBy('lifecycle_stage')->pluck('n', 'lifecycle_stage');
        $out = array_fill_keys(self::STAGES, 0);
        foreach ($rows as $stage => $n) {
            $out[$stage ?: 'lead'] += (int) $n;
        }

        return $out;
    }
}
