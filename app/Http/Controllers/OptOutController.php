<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\User;
use App\Services\Consent\Consent;
use App\Services\Consent\OptOut;
use Illuminate\Http\Request;

/**
 * Public page behind the "stop receiving messages" link. Opening the link changes nothing (mail scanners and chat apps
 * open links to preview them); the person confirms with a button. They can also change their mind on the same page.
 */
class OptOutController extends Controller
{
    public function show(int $contact, string $signature)
    {
        [$c, $owner] = $this->resolve($contact, $signature);

        return $this->page($c, $owner, null);
    }

    public function confirm(Request $request, int $contact, string $signature)
    {
        [$c, $owner] = $this->resolve($contact, $signature);
        $reason = trim((string) $request->input('reason', ''));
        app(Consent::class)->revoke($c, 'link', $reason !== '' ? __('Opt-out link') . ': ' . mb_substr($reason, 0, 300) : __('Opt-out link'), $request->ip());

        return $this->page($c->fresh(), $owner, 'out');
    }

    public function undo(Request $request, int $contact, string $signature)
    {
        [$c, $owner] = $this->resolve($contact, $signature);
        app(Consent::class)->grant($c, 'link', __('Opted back in through the link'), $request->ip());

        return $this->page($c->fresh(), $owner, 'in');
    }

    private function resolve(int $contact, string $signature): array
    {
        abort_unless(OptOut::valid($contact, $signature), 404);
        $c = Contact::findOrFail($contact);

        return [$c, User::findOrFail($c->user_id)];
    }

    private function page(Contact $c, User $owner, ?string $done)
    {
        $out = $c->consent_status === 'revoked' && (int) $c->ignore_me === 1;

        return response()->view('optout.page', [
            'company' => $owner->name, 'out' => $out, 'done' => $done,
            'confirmUrl' => route('optout.confirm', ['contact' => $c->id, 'signature' => OptOut::signature($c->id)]),
            'undoUrl' => route('optout.undo', ['contact' => $c->id, 'signature' => OptOut::signature($c->id)]),
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
