<?php

namespace App\Services\Contacts;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Consent\Consent;

/** Mass actions on selected contacts. Only contacts of the account are ever touched. */
class ContactBulk
{
    public const MAX = 1000;

    /** @param array<int,mixed> $ids */
    private function mine(User $owner, array $ids)
    {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX);

        return Contact::where('user_id', $owner->id)->whereIn('id', $ids);
    }

    /** Company of the account, or null to clear. Returns how many contacts changed. */
    public function setCompany(User $owner, array $ids, ?int $companyId): int
    {
        if ($companyId) {
            abort_unless(Company::where('user_id', $owner->id)->whereKey($companyId)->exists(), 422, __('Choose a company of your account.'));
        }
        $n = 0;
        // one by one on purpose: the model events carry the company over to the deals of each person
        $this->mine($owner, $ids)->get()->each(function (Contact $c) use ($companyId, &$n) {
            if ((int) $c->company_id !== (int) $companyId) {
                $c->update(['company_id' => $companyId]);
                $n++;
            }
        });

        return $n;
    }

    public function optOut(User $owner, User $actor, array $ids): int
    {
        $n = 0;
        $this->mine($owner, $ids)->get()->each(function (Contact $c) use ($actor, &$n) {
            if ($c->consent_status !== 'revoked') {
                app(Consent::class)->revoke($c, 'manual', __('Bulk action by a person'), null, $actor->id);
                $n++;
            }
        });

        return $n;
    }
}
