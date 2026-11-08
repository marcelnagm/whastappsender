<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Services\Pipeline\StageChecklist;
use App\Services\Team\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Checklist of the stage a deal is in (JSON, used by the deal window). */
class DealChecklistController extends Controller
{
    public function index(Request $request, int $deal, StageChecklist $svc): JsonResponse
    {
        $d = $this->deal($request, $deal);

        return response()->json(['items' => $svc->state($d)]);
    }

    public function toggle(Request $request, int $deal, string $hash, StageChecklist $svc): JsonResponse
    {
        $d = $this->deal($request, $deal);
        $data = $request->validate(['done' => 'required|boolean']);
        abort_unless($svc->set($d, $hash, (bool) $data['done'], TeamContext::actor($request)->id ?? null), 404);

        return response()->json(['items' => $svc->state($d)]);
    }

    private function deal(Request $request, int $id): Deal
    {
        return Deal::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
