<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\WhatsappJob;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Class CampaignController
 * @package App\Http\Controllers
 */
class CampaignController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $campaigns = Campaign::where('user_id', Auth::user()->id)->paginate();

        return view('campaign.index', compact('campaigns'))
            ->with('i', (request()->input('page', 1) - 1) * $campaigns->perPage());
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $campaign = new Campaign();
        return view('campaign.create', compact('campaign'));
    }

    public function report(Campaign $campaign)
    {
        $this->authorizeOwner($campaign);

        // Carrega a campanha com as contagens de cada status dos itens (mensagens)
        $stats = $campaign->summary();

        $total = $stats->total ?: 1; // Evita divisão por zero

        $data = [
            'campaign' => $campaign,
            'total' => $stats->total,
            'errors' => ['count' => $stats->errors, 'percent' => round(($stats->errors / $total) * 100, 1)],
            'sent' => ['count' => $stats->sent, 'percent' => round(($stats->sent / $total) * 100, 1)],
            'delivered' => ['count' => $stats->delivered, 'percent' => round(($stats->delivered / $total) * 100, 1)],
            'read' => ['count' => $stats->read_count, 'percent' => round(($stats->read_count / $total) * 100, 1)],
            'items' => $campaign->campaignItems()->paginate(50)     // Detalhado por item
        ];

        return view('campaign.report', $data);
    }


    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        request()->validate(Campaign::$rules);
        $data = $request->all();
        $data['user_id'] = Auth::user()->id;

        $campaign = Campaign::create($data);

        return redirect()->route('campaigns.index')
            ->with('success', 'Campaign created successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $campaign = Campaign::with('campaignItems')->find($id);
        $campaignItems = $campaign->campaignItems()->paginate(10);

        return view('campaign.show', compact('campaign', 'campaignItems'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $campaign = Campaign::find($id);

        return view('campaign.edit', compact('campaign'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  Campaign $campaign
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Campaign $campaign)
    {
        request()->validate(Campaign::$rules);

        $campaign->update($request->all());

        return redirect()->route('campaigns.index')
            ->with('success', 'Campaign updated successfully');
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy($id)
    {
        // 1. Localiza a campanha ou falha
        $campaign = Campaign::with('campaignItems')->findOrFail($id);

        try {
            $campaign->delete();

            return redirect()->route('campaigns.index')
                ->with('success', 'Campaign and all associated media were deleted.');
        } catch (\Exception $e) {
            return redirect()->route('campaigns.index')
                ->with('error', 'Deletion failed: ' . $e->getMessage());
        }
    }

    private function authorizeOwner(Campaign $campaign)
    {
        if ($campaign->user_id !== Auth::id()) {
            abort(403, 'Access denied for this campaign.');
        }
    }
}
