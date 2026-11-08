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
            return redirect()->back()->with('error', __('No contacts selected.'));
        }

        // Allowed statuses only (prevents invalid injected values)
        $statusPermitidos = ['ativo', 'inativo', 'no-whatsapp'];
        if (!in_array($novoStatus, $statusPermitidos)) {
            return redirect()->back()->with('error', __('Invalid status requested.'));
        }

        try {
            $query = \App\Models\Contact::whereIn('id', $ids);

            // Non-admins may only change their own contacts
            if (auth()->user()->role !== 'admin') {
                $query->where('user_id', auth()->id());
            }

            $afetados = $query->update(['status' => $novoStatus]);

            return redirect()->back()->with('success', __('Status updated for :count contact(s) to \':status\'.', ['count' => $afetados, 'status' => $novoStatus]));
        } catch (\Exception $e) {
            \Log::error("Bulk status error: " . $e->getMessage());
            return redirect()->back()->with('error', __('Failed to update contact status.'));
        }
    }

    /** Company assignment / opt-out for the selected contacts. */
    public function bulkUpdate(Request $request)
    {
        $data = $request->validate(['ids' => 'required|string', 'action' => 'required|in:company,optout,lifecycle', 'company_id' => 'nullable|integer', 'stage' => 'nullable|string|max:20']);
        $ids = json_decode($data['ids'], true);
        if (!is_array($ids) || !$ids) {
            return back()->with('error', __('No contacts selected.'));
        }
        $svc = app(\App\Services\Contacts\ContactBulk::class);
        $n = match ($data['action']) {
            'company' => $svc->setCompany($request->user(), $ids, !empty($data['company_id']) ? (int) $data['company_id'] : null),
            'lifecycle' => $svc->setLifecycle($request->user(), $ids, $data['stage'] ?? null),
            default => $svc->optOut($request->user(), \App\Services\Team\TeamContext::actor($request), $ids),
        };

        return back()->with('success', __(':n contact(s) updated.', ['n' => $n]));
    }

    /**
     * Permanently remove multiple contacts.
     * Bulk delete with user scope protection.
     */
    public function bulkDelete(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', __('Invalid selection for removal.'));
        }

        try {
            $query = \App\Models\Contact::whereIn('id', $ids);

            // Prevent deleting another user's contacts via forged ids
            if (auth()->user()->role !== 'admin') {
                $query->where('user_id', auth()->id());
            }

            $deletados = $query->delete();

            return redirect()->back()->with('success', __(':count contact(s) removed from your database.', ['count' => $deletados]));
        } catch (\Exception $e) {
            \Log::error("Bulk delete error: " . $e->getMessage());
            return redirect()->back()->with('error', __('Internal error while processing bulk delete.'));
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
            ->when(in_array($request->input('tier'), ['hot', 'warm', 'cold', 'frozen'], true), fn ($q) => $q->tier($request->input('tier')))
            ->when(in_array($request->input('stage'), \App\Services\Contacts\Lifecycle::STAGES, true), fn ($q) => $request->input('stage') === 'lead'
                ? $q->where(fn ($w) => $w->whereNull('lifecycle_stage')->orWhere('lifecycle_stage', 'lead')) : $q->where('lifecycle_stage', $request->input('stage')))
            ->when($request->input('sort') === 'score', fn ($q) => $q->orderByDesc('score'))
            ->orderBy('name', 'asc')
            ->paginate(20)->withQueryString(); // 20 per page for usability

        $lifecycle = app(\App\Services\Contacts\Lifecycle::class)->counts($request->user());

        return view('contact.index', compact('contacts', 'lifecycle'))
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
        app(\App\Services\Billing\Entitlements::class)->authorizeUsage(Auth::user(), 'contacts');
        $data = $request->except('custom');
        $data['user_id'] = Auth::user()->id;
        $data['custom'] = app(\App\Services\Contacts\CustomFields::class)->clean($data['user_id'], (array) $request->input('custom')) ?: null;
        $contact = Contact::create($data);

        return redirect()->route('contacts.index')
            ->with('success', __('Contact created successfully.'));
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
        $contact = $this->owned($id);
        $contact->syncFromEvolution();
        return response($contact->profile_url, 200)
            ->header('Content-Type', 'text/plain');
    }


    public function show($id)
    {
        $contact = $this->owned($id);
        $timeline = app(\App\Services\Contacts\ContactTimeline::class)->for($contact);

        return view('contact.show', compact('contact', 'timeline'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $contact = $this->owned($id);

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
        $this->owned($contact->id);
        request()->validate(Contact::$rules);

        $data = $request->except(['custom', 'user_id']);
        // the custom fields of the form replace the stored ones (a cleared input removes the value)
        $data['custom'] = app(\App\Services\Contacts\CustomFields::class)->clean((int) $contact->user_id, (array) $request->input('custom')) ?: null;
        $contact->update($data);

        return redirect()->route('contacts.index')
            ->with('success', __('Contact updated successfully'));
    }

    /**
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function destroy($id)
    {
        $this->owned($id)->delete();

        return redirect()->route('contacts.index')
            ->with('success', __('Contact deleted successfully'));
    }


    public function import(Request $request)
    {
        if ($request->input('renover'))
            Contact::where('user_id', Auth::user()->id)->delete();
        $import = new ContactsImport($request->boolean('consent') ? 'import' : null);
        Excel::import($import, $request->file('importer'));

        $note = $import->skipped > 0
            ? ' ' . __(':n contact(s) were not imported: plan limit reached.', ['n' => $import->skipped])
            : '';

        return redirect()->route('contacts.index')
            ->with('success', __('Contact imported') . $note);
    }
    public function clean(Request $request)
    {

        Contact::where('user_id', Auth::user()->id)->delete();

        return redirect()->route('contacts.index')
            ->with('success', __('Contacts Cleared'));
    }

    /** A contact of the logged account (admins reach any); someone else's id is a 404. */
    private function owned($id): Contact
    {
        return Contact::when(auth()->user()->role !== 'admin', fn ($q) => $q->where('user_id', auth()->id()))->findOrFail($id);
    }
}
