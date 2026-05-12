<?php

namespace App\Http\Controllers;

use App\Imports\ContactsImport;
use App\Models\Contact;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Auth;

/**
 * Class ContactController
 * @package App\Http\Controllers
 */
class ContactController extends Controller
{
    /**
     * Bulk-update contact status (active, inactive, no-whatsapp, etc.).
     * Uses a mass update for fewer database round-trips.
     */
    public function bulkStatus(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);
        $novoStatus = $request->input('status_value'); // e.g. ativo, inativo

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', 'No contacts selected.');
        }

        // Allowed statuses only (prevents invalid injected values)
        $statusPermitidos = ['ativo', 'inativo', 'no-whatsapp'];
        if (!in_array($novoStatus, $statusPermitidos)) {
            return redirect()->back()->with('error', 'Invalid status requested.');
        }

        try {
            $query = \App\Models\Contact::whereIn('id', $ids);

            // Non-admins may only change their own contacts
            if (auth()->user()->role !== 'admin') {
                $query->where('user_id', auth()->id());
            }

            $afetados = $query->update(['status' => $novoStatus]);

            return redirect()->back()->with('success', "Status updated for {$afetados} contact(s) to '{$novoStatus}'.");
        } catch (\Exception $e) {
            \Log::error("Bulk status error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update contact status.');
        }
    }

    /**
     * Permanently remove multiple contacts.
     * Bulk delete with user scope protection.
     */
    public function bulkDelete(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', 'Invalid selection for removal.');
        }

        try {
            $query = \App\Models\Contact::whereIn('id', $ids);

            // Prevent deleting another user's contacts via forged ids
            if (auth()->user()->role !== 'admin') {
                $query->where('user_id', auth()->id());
            }

            $deletados = $query->delete();

            return redirect()->back()->with('success', "{$deletados} contact(s) removed from your database.");
        } catch (\Exception $e) {
            \Log::error("Bulk delete error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Internal error while processing bulk delete.');
        }
    }

    public function index(Request $request)
    {
        $search = $request->input('search');

        $contacts = Contact::where('user_id', auth()->id())
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('contact', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name', 'asc')
            ->paginate(20); // 20 per page for usability

        return view('contact.index', compact('contacts'))
            ->with('i', (request()->input('page', 1) - 1) * $contacts->perPage());
    }



    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $contact = new Contact();
        return view('contact.create', compact('contact'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        request()->validate(Contact::$rules);
        $data = $request->all();
        $data['user_id'] = Auth::user()->id;
        $contact = Contact::create($data);

        return redirect()->route('contacts.index')
            ->with('success', 'Contact created successfully.');
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function photo($id)
    {
        if (class_exists('\Debugbar')) {
            \Debugbar::disable();
        }
        $contact = Contact::findOrFail($id);
        $contact->syncFromEvolution();
        return response($contact->profile_url, 200)
            ->header('Content-Type', 'text/plain');
    }


    public function show($id)
    {
        $contact = Contact::find($id);

        return view('contact.show', compact('contact'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $contact = Contact::find($id);

        return view('contact.edit', compact('contact'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     * @param  Contact $contact
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Contact $contact)
    {
        request()->validate(Contact::$rules);

        $contact->update($request->all());

        return redirect()->route('contacts.index')
            ->with('success', 'Contact updated successfully');
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy($id)
    {
        $contact = Contact::find($id)->delete();

        return redirect()->route('contacts.index')
            ->with('success', 'Contact deleted successfully');
    }


    public function import(Request $request)
    {
        if ($request->input('renover'))
            Contact::where('user_id', Auth::user()->id)->delete();
        Excel::import(new ContactsImport, $request->file('importer'));

        return redirect()->route('contacts.index')
            ->with('success', 'Contact imported');
    }
    public function clean(Request $request)
    {

        Contact::where('user_id', Auth::user()->id)->delete();

        return redirect()->route('contacts.index')
            ->with('success', 'Contacts Cleared');
    }
}
