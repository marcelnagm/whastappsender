<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Services\Pipeline\DealFollowers;
use App\Services\Team\TeamContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Follow / unfollow a deal (JSON, used by the deal window). */
class DealFollowersController extends Controller
{
    public function index(Request $request, int $deal, DealFollowers $svc): JsonResponse
    {
        return $this->payload($this->deal($request, $deal), $request, $svc);
    }

    public function toggle(Request $request, int $deal, DealFollowers $svc): JsonResponse
    {
        $d = $this->deal($request, $deal);
        $data = $request->validate(['follow' => 'required|boolean']);
        $me = (int) (TeamContext::actor($request)->id ?? $request->user()->id);
        $data['follow'] ? $svc->follow($d, $me) : $svc->unfollow($d, $me);

        return $this->payload($d, $request, $svc);
    }

    private function payload(Deal $d, Request $request, DealFollowers $svc): JsonResponse
    {
        $me = (int) (TeamContext::actor($request)->id ?? $request->user()->id);
        $list = $svc->list($d);

        return response()->json(['followers' => $list, 'following' => collect($list)->contains('id', $me)]);
    }

    private function deal(Request $request, int $id): Deal
    {
        return Deal::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
