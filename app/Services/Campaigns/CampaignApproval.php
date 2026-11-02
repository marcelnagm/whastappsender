<?php

namespace App\Services\Campaigns;

use App\Models\CampaignItem;
use App\Models\User;
use App\Services\Team\TeamContext;
use Illuminate\Validation\ValidationException;

/**
 * Approval of campaign messages. A team member can launch only what an approver approved; the account owner (and the
 * system administrator) can launch directly. Approving stores the chosen audience, launching uses it.
 */
class CampaignApproval
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const LAUNCHED = 'launched';

    public function __construct(private CampaignAudience $audiences)
    {
    }

    /** The creator (or anyone allowed) asks for review. */
    public function submit(CampaignItem $item, User $actor, ?array $audience = null): CampaignItem
    {
        if ($audience !== null) {
            $item->audience = CampaignAudience::normalize($audience);
        }
        $item->forceFill(['approval_status' => self::PENDING, 'submitted_by' => $actor->id, 'approved_by' => null, 'approved_at' => null, 'approval_note' => null])->save();

        return $item;
    }

    /** @throws ValidationException when the audience is empty */
    public function approve(CampaignItem $item, User $actor, array $audience, ?string $note = null): CampaignItem
    {
        $audience = CampaignAudience::normalize($audience);
        $count = $this->audiences->count((int) $item->user_id, $audience);

        if ($count['total'] === 0) {
            throw ValidationException::withMessages(['audience' => __('No contact matches this audience.')]);
        }

        $item->forceFill([
            'audience' => $audience, 'audience_count' => $count['total'],
            'approval_status' => self::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now(),
            'approval_note' => $note !== null ? mb_substr($note, 0, 1000) : null,
        ])->save();

        return $item;
    }

    public function reject(CampaignItem $item, User $actor, ?string $note = null): CampaignItem
    {
        $item->forceFill([
            'approval_status' => self::REJECTED, 'approved_by' => $actor->id, 'approved_at' => now(),
            'approval_note' => $note !== null ? mb_substr($note, 0, 1000) : null,
        ])->save();

        return $item;
    }

    /** Saves a chosen audience without changing the approval state (owner / approver tuning before launch). */
    public function setAudience(CampaignItem $item, array $audience): CampaignItem
    {
        $audience = CampaignAudience::normalize($audience);
        $item->forceFill(['audience' => $audience, 'audience_count' => $this->audiences->count((int) $item->user_id, $audience)['total']])->save();

        return $item;
    }

    /** Null = may launch; otherwise the reason (translated). */
    public function launchBlocker(CampaignItem $item): ?string
    {
        if (app(TeamContext::class)->isMember() && $item->approval_status !== self::APPROVED) {
            return __('This message needs approval before it can be sent.');
        }

        return null;
    }

    /** Called when the launch starts: an approval is used up by the launch it allowed. */
    public function markLaunched(CampaignItem $item): void
    {
        $update = ['launched_at' => now()];
        if ($item->approval_status === self::APPROVED) {
            $update['approval_status'] = self::LAUNCHED;
        }
        $item->forceFill($update)->save();
    }

    public function present(CampaignItem $i): array
    {
        return [
            'id' => $i->id, 'name' => $i->name, 'campaign_id' => $i->campaign_id,
            'approval_status' => $i->approval_status, 'approval_note' => $i->approval_note,
            'audience' => $i->audience, 'audience_count' => $i->audience_count,
            'submitted_by' => $i->submitted_by, 'approved_by' => $i->approved_by,
            'approved_at' => optional($i->approved_at)->toIso8601String(), 'launched_at' => optional($i->launched_at)->toIso8601String(),
        ];
    }
}
