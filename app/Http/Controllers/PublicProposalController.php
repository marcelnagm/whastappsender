<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Models\User;
use App\Services\Pipeline\Proposals;
use Illuminate\Http\Request;

/** What the customer sees: no login, the long random token is the access. */
class PublicProposalController extends Controller
{
    public function __construct(private Proposals $proposals)
    {
    }

    public function show(Request $request, string $token)
    {
        $p = $this->find($token);
        // the owner previewing (?preview=1 while logged in) does not count as the customer opening it
        $preview = $request->boolean('preview') && $request->user() && (int) $request->user()->id === (int) $p->user_id;
        if (!$preview) {
            $this->proposals->markViewed($p);
        }

        return response()->view('proposals.public', ['p' => $p->fresh(), 'company' => User::find($p->user_id)?->name, 'preview' => $preview])
            ->header('X-Robots-Tag', 'noindex, nofollow')->header('Referrer-Policy', 'no-referrer');
    }

    public function accept(Request $request, string $token)
    {
        $p = $this->find($token);
        $data = $request->validate(['name' => 'required|string|min:3|max:120', 'agree' => 'accepted']);
        $this->proposals->accept($p, $data['name'], $request->ip());

        return redirect()->route('proposals.public.show', $token)->with('done', __('Thank you! Your acceptance was recorded.'));
    }

    public function decline(Request $request, string $token)
    {
        $p = $this->find($token);
        $data = $request->validate(['reason' => 'nullable|string|max:300']);
        $this->proposals->decline($p, $data['reason'] ?? null, $request->ip());

        return redirect()->route('proposals.public.show', $token)->with('done', __('Your answer was recorded.'));
    }

    private function find(string $token): Proposal
    {
        abort_unless(preg_match('/^[a-z0-9]{40}$/', $token), 404);

        return Proposal::where('token', $token)->firstOrFail();
    }
}
