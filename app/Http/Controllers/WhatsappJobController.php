<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WhatsappJob;

class WhatsappJobController extends Controller
{
    //
    public function index(Request $request)
{
    $query = WhatsappJob::query();

    if ($request->has('campaign_item_id')) {
        $query->where('campaign_item_id', $request->campaign_item_id);
    }

    $jobs = $query->with('campaignItem')->orderBy('created_at', 'desc')->paginate(50);

    return view('whatsapp-jobs.index', compact('jobs'));
}
}
