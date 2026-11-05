<?php

namespace App\Http\Controllers\Api;

use App\Models\CampaignItem;
use App\Services\Campaigns\CampaignApproval;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Approval flow and audience (score + channels) of campaign messages. */
class ApprovalController extends ApiController
{
    public function __construct(private CampaignApproval $approval, private CampaignAudience $audiences)
    {
    }

    public function pending()
    {
        $items = $this->scopedToUser(CampaignItem::query())->where('approval_status', CampaignApproval::PENDING)->orderBy('updated_at')->get();

        return $this->success($items->map(fn ($i) => $this->approval->present($i) + ['text' => $i->text])->values());
    }

    /** How many contacts a filter reaches; with save=true the audience is stored on the message. */
    public function audience(Request $request, CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);
        $audience = CampaignAudience::normalize($request->only(['tiers', 'min_score', 'max_score', 'channels', 'segment_id']));
        $count = $this->audiences->count((int) $campaignItem->user_id, $audience);

        if ($request->boolean('save')) {
            $this->approval->setAudience($campaignItem, $audience);
        }

        return $this->success(['audience' => $audience] + $count + ['saved' => $request->boolean('save')]);
    }

    public function submit(Request $request, CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);
        $audience = $request->has('channels') || $request->has('tiers') ? CampaignAudience::normalize($request->only(['tiers', 'min_score', 'max_score', 'channels', 'segment_id'])) : null;
        $this->approval->submit($campaignItem, TeamContext::actor($request), $audience);

        return $this->success($this->approval->present($campaignItem->fresh()), 'Submitted for approval.');
    }

    public function approve(Request $request, CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);
        $audience = $request->has('channels') || $request->has('tiers') || $request->has('min_score') || $request->has('max_score')
            ? $request->only(['tiers', 'min_score', 'max_score', 'channels', 'segment_id']) : ($campaignItem->audience ?? []);
        $this->approval->approve($campaignItem, TeamContext::actor($request), $audience, $request->input('note'));

        return $this->success($this->approval->present($campaignItem->fresh()), 'Approved.');
    }

    public function reject(Request $request, CampaignItem $campaignItem)
    {
        $this->authorizeOwner($campaignItem);
        $this->approval->reject($campaignItem, TeamContext::actor($request), $request->input('note'));

        return $this->success($this->approval->present($campaignItem->fresh()), 'Rejected.');
    }
}
