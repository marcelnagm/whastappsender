<?php

namespace App\Http\Controllers\Api;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CampaignController extends ApiController
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 20), 100);

        $campaigns = $this->scopedToUser(Campaign::query())
            ->withCount('campaignItems')
            ->latest()
            ->paginate($perPage);

        return $this->paginated($campaigns);
    }

    public function store(Request $request)
    {
        $data = $request->validate(Campaign::$rules);
        $data['user_id'] = Auth::id();

        $campaign = Campaign::create($data);

        return $this->success($campaign, 'Campaign created.', 201);
    }

    public function show(Campaign $campaign)
    {
        $this->authorizeOwner($campaign);

        $campaign->load(['campaignItems' => function ($q) {
            $q->latest()->limit(50);
        }]);

        return $this->success($campaign);
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->authorizeOwner($campaign);

        $data = $request->validate(Campaign::$rules);
        $campaign->update($data);

        return $this->success($campaign->fresh(), 'Campaign updated.');
    }

    public function destroy(Campaign $campaign)
    {
        $this->authorizeOwner($campaign);

        try {
            $campaign->delete();
            return $this->success(null, 'Campaign and associated items deleted.');
        } catch (\Exception $e) {
            return $this->error('Deletion failed: ' . $e->getMessage(), 500);
        }
    }

    public function report(Campaign $campaign)
    {
        $this->authorizeOwner($campaign);

        $stats = $campaign->summary();
        $total = $stats->total ?: 1;

        return $this->success([
            'campaign' => $campaign,
            'total' => $stats->total,
            'errors' => ['count' => $stats->errors, 'percent' => round(($stats->errors / $total) * 100, 1)],
            'sent' => ['count' => $stats->sent, 'percent' => round(($stats->sent / $total) * 100, 1)],
            'delivered' => ['count' => $stats->delivered, 'percent' => round(($stats->delivered / $total) * 100, 1)],
            'read' => ['count' => $stats->read_count, 'percent' => round(($stats->read_count / $total) * 100, 1)],
        ]);
    }
}
