<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Services\Contacts\ContactDuplicates;
use App\Services\Contacts\ContactMerge;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

/** Review of people registered twice: merge them, or say they are different people. */
class ContactDuplicatesController extends Controller
{
    public function index(Request $request)
    {
        return view('contact.duplicates', ['groups' => app(ContactDuplicates::class)->groups($request->user()->id)]);
    }

    public function merge(Request $request)
    {
        $uid = $request->user()->id;
        $data = $request->validate(['keep_id' => 'required|integer', 'drop_ids' => 'required|array|min:1|max:20', 'drop_ids.*' => 'integer']);
        $keep = Contact::where('user_id', $uid)->findOrFail($data['keep_id']);
        $merger = app(ContactMerge::class);
        $n = 0;
        foreach (array_unique($data['drop_ids']) as $id) {
            if ((int) $id === $keep->id) {
                continue;
            }
            $keep = $merger->merge($keep, Contact::where('user_id', $uid)->findOrFail($id), TeamContext::actor($request)->id);
            $n++;
        }

        return redirect()->route('contacts.duplicates')->with('success', trans_choice(':n contact merged.|:n contacts merged.', $n, ['n' => $n]));
    }

    public function ignore(Request $request)
    {
        $data = $request->validate(['keys' => 'required|array|min:1|max:10', 'keys.*' => 'string|max:120']);
        app(ContactDuplicates::class)->ignore($request->user()->id, $data['keys']);

        return redirect()->route('contacts.duplicates')->with('success', __('Marked as different people.'));
    }
}
