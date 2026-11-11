<?php

namespace App\Http\Controllers;

use App\Services\Chat\ChatAssignment;
use App\Services\Dashboard\MissionControl;
use App\Services\Team\TeamContext;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function index(MissionControl $control)
    {
        if ($owner = Auth::user()) {
            $team = app(TeamContext::class);
            $mc = $control->build($owner, app(ChatAssignment::class)->restrictedTo($owner), [
                'chat' => $team->can('chat'), 'deals' => $team->can('deals'), 'tasks' => $team->can('tasks'),
            ]);

            return view('home.index', compact('mc'));
        }

        // Public pricing on the landing page (skipped until the billing migration has run)
        $plans = \Illuminate\Support\Facades\Schema::hasTable('plans')
            ? \App\Models\Plan::where('is_active', true)->orderBy('sort')->get()
            : collect();

        return view('home.guest', compact('plans'));
    }
}
