<?php

namespace App\Http\Controllers;

use App\Services\Stats\ChannelStats;
use Illuminate\Http\Request;

class ChannelStatsController extends Controller
{
    public function index(Request $request, ChannelStats $stats)
    {
        $data = $stats->summary($request->user(), (int) $request->query('days', 30));
        $data['max'] = max(1, collect($data['daily'])->map(fn ($d) => array_sum($d))->max() ?: 1);

        return view('stats.index', $data);
    }
}
