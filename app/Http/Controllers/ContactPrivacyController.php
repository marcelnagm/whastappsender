<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\Contacts\ContactPrivacy;
use Illuminate\Http\Request;

/** Data-subject requests for one contact: take a copy of the data or erase it. */
class ContactPrivacyController extends Controller
{
    public function export(Request $request, int $id, ContactPrivacy $svc)
    {
        $c = $this->owned($request, $id);

        return response()->streamDownload(
            fn () => print json_encode($svc->export($c), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'contact-' . $c->id . '-data.json', ['Content-Type' => 'application/json']
        );
    }

    public function erase(Request $request, int $id, ContactPrivacy $svc)
    {
        $c = $this->owned($request, $id);
        $request->validate(['confirm' => 'required|accepted']);
        $stats = $svc->erase($c);

        return redirect()->route('contacts.show', $c->id)->with('success', __('Personal data erased (:n messages wiped).', ['n' => $stats['messages']]));
    }

    private function owned(Request $request, int $id): Contact
    {
        return Contact::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
