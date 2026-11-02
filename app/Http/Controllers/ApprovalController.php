<?php

namespace App\Http\Controllers;

use App\Models\CampaignItem;
use App\Services\Campaigns\CampaignApproval;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Review of campaign messages: choose the audience (score + channels) and approve, reject or launch. */
class ApprovalController extends Controller
{
    public function __construct(private CampaignApproval $approval, private CampaignAudience $audiences, private TeamContext $team)
    {
    }

    public function index(Request $request)
    {
        $base = CampaignItem::where('user_id', $request->user()->id);

        return view('approvals.index', [
            'pending' => (clone $base)->where('approval_status', CampaignApproval::PENDING)->orderBy('updated_at')->get(),
            'recent' => (clone $base)->whereIn('approval_status', [CampaignApproval::APPROVED, CampaignApproval::REJECTED, CampaignApproval::LAUNCHED])->orderByDesc('approved_at')->limit(20)->get(),
        ]);
    }

    public function show(Request $request, int $item)
    {
        $item = $this->item($request, $item);
        $audience = CampaignAudience::normalize($item->audience);

        return view('approvals.show', [
            'item' => $item,
            'audience' => $audience,
            'count' => $this->audiences->count($item->user_id, $audience),
            'canLaunch' => $this->team->can('campaigns.send'),
            'tiers' => CampaignAudience::TIERS,
            'channels' => CampaignAudience::CHANNELS,
        ]);
    }

    /** Live "how many contacts" while the approver changes the filters. */
    public function preview(Request $request, int $item)
    {
        $item = $this->item($request, $item);

        return response()->json($this->audiences->count($item->user_id, $this->audienceFrom($request)));
    }

    /** Creator asks for review (may suggest an audience). */
    public function submit(Request $request, int $item)
    {
        $item = $this->item($request, $item);
        $this->approval->submit($item, TeamContext::actor($request), $request->has('channels') || $request->has('tiers') ? $this->audienceFrom($request) : null);

        return redirect()->route('campaign-items.index')->with('success', __('Submitted for approval.'));
    }

    public function approve(Request $request, int $item)
    {
        $item = $this->item($request, $item);
        $this->approval->approve($item, TeamContext::actor($request), $this->audienceFrom($request), $request->input('note'));

        if ($request->boolean('launch')) {
            abort_unless($this->team->can('campaigns.send'), 403, __('Your role does not allow this action.'));

            return redirect()->route('campaign-items.generateAll', $item->id);
        }

        return redirect()->route('approvals.index')->with('success', __('Approved. The audience was saved.'));
    }

    public function reject(Request $request, int $item)
    {
        $item = $this->item($request, $item);
        $this->approval->reject($item, TeamContext::actor($request), $request->input('note'));

        return redirect()->route('approvals.index')->with('success', __('Message rejected.'));
    }

    private function item(Request $request, int $id): CampaignItem
    {
        return CampaignItem::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function audienceFrom(Request $request): array
    {
        return CampaignAudience::normalize($request->only(['tiers', 'min_score', 'max_score', 'channels']));
    }
}
