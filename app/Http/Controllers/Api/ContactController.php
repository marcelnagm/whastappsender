<?php

namespace App\Http\Controllers\Api;

use App\Imports\ContactsImport;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ContactController extends ApiController
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 20), 100);

        $query = $this->scopedToUser(Contact::query())
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('contact', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name', 'asc');

        return $this->paginated($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge(Contact::$rules, [
            'email' => 'nullable|email',
            'status' => 'nullable|in:ativo,inativo,no-whatsapp',
        ]));

        $data['user_id'] = Auth::id();
        $contact = Contact::create($data);

        return $this->success($contact, 'Contact created.', 201);
    }

    public function show(Contact $contact)
    {
        $this->authorizeOwner($contact);

        return $this->success($contact);
    }

    public function update(Request $request, Contact $contact)
    {
        $this->authorizeOwner($contact);

        $data = $request->validate(array_merge(Contact::$rules, [
            'email' => 'nullable|email',
            'status' => 'nullable|in:ativo,inativo,no-whatsapp',
        ]));

        $contact->update($data);

        return $this->success($contact->fresh(), 'Contact updated.');
    }

    public function destroy(Contact $contact)
    {
        $this->authorizeOwner($contact);
        $contact->delete();

        return $this->success(null, 'Contact deleted.');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,xlsx,xls,txt',
            'replace' => 'nullable|boolean',
        ]);

        if ($request->boolean('replace')) {
            Contact::where('user_id', Auth::id())->delete();
        }

        Excel::import(new ContactsImport, $request->file('file'));

        return $this->success(null, 'Contacts imported.');
    }

    public function clean()
    {
        $count = Contact::where('user_id', Auth::id())->delete();

        return $this->success(['deleted' => $count], 'All contacts cleared.');
    }

    public function syncPhoto(Contact $contact)
    {
        $this->authorizeOwner($contact);
        $synced = $contact->syncFromEvolution();

        return $this->success([
            'synced' => $synced,
            'profile_url' => $contact->fresh()->profile_url,
            'status' => $contact->fresh()->status,
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $query = $this->scopedToUser(Contact::whereIn('id', $data['ids']));
        $deleted = $query->delete();

        return $this->success(['deleted' => $deleted], "{$deleted} contact(s) removed.");
    }

    public function bulkStatus(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
            'status' => 'required|in:ativo,inativo,no-whatsapp',
        ]);

        $updated = $this->scopedToUser(Contact::whereIn('id', $data['ids']))
            ->update(['status' => $data['status']]);

        return $this->success(['updated' => $updated], "Status updated for {$updated} contact(s).");
    }
}
