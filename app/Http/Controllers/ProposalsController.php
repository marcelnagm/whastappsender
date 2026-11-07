<?php

namespace App\Http\Controllers;

use App\Models\Deal;
use App\Models\Proposal;
use App\Services\Pipeline\Proposals;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Owner side of the proposals: create from a deal, edit the text, copy the link, follow the answer. */
class ProposalsController extends Controller
{
    public function __construct(private Proposals $proposals)
    {
    }

    public function index(Request $request)
    {
        $rows = Proposal::where('user_id', $request->user()->id)->orderByDesc('id')->limit(200)->get();

        return view('proposals.index', ['proposals' => $rows, 'summary' => [
            'open' => $rows->whereIn('status', ['draft', 'sent', 'viewed'])->count(),
            'accepted' => $rows->where('status', 'accepted')->count(),
            'declined' => $rows->where('status', 'declined')->count(),
            'accepted_value' => (float) $rows->where('status', 'accepted')->sum('total'),
        ]]);
    }

    public function store(Request $request, int $deal)
    {
        $d = Deal::where('user_id', $request->user()->id)->findOrFail($deal);
        $p = $this->proposals->createFor($d, TeamContext::actor($request)->id);

        return redirect()->route('proposals.show', $p->id)->with('success', __('Proposal created.'));
    }

    public function show(Request $request, int $proposal)
    {
        $p = $this->find($request, $proposal);

        return view('proposals.show', ['p' => $p, 'deal' => Deal::with('contact:id,name,contact')->find($p->deal_id)]);
    }

    public function update(Request $request, int $proposal)
    {
        $p = $this->find($request, $proposal);
        abort_if($p->isAnswered(), 422, __('This proposal was already answered.'));
        $data = $request->validate(['title' => 'required|string|max:160', 'intro' => 'nullable|string|max:3000', 'terms' => 'nullable|string|max:5000', 'valid_until' => 'nullable|date']);
        $p->update($data);

        return back()->with('success', __('Proposal saved.'));
    }

    public function refresh(Request $request, int $proposal)
    {
        $this->proposals->refresh($this->find($request, $proposal));

        return back()->with('success', __('Items updated from the deal.'));
    }

    public function sent(Request $request, int $proposal)
    {
        $this->proposals->markSent($this->find($request, $proposal));

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $proposal)
    {
        $this->find($request, $proposal)->delete();

        return redirect()->route('proposals.index')->with('success', __('Proposal removed.'));
    }

    private function find(Request $request, int $id): Proposal
    {
        return Proposal::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
