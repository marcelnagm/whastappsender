<?php

namespace App\Http\Controllers\Api;

use App\Services\Channels\ContactLinks;
use App\Services\Contacts\UnresponsiveContacts;
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
            ->when(in_array($request->input('tier'), ['hot', 'warm', 'cold', 'frozen'], true), fn ($q) => $q->tier($request->input('tier')))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($request->input('sort') === 'score', fn ($q) => $q->orderByDesc('score'))
            ->orderBy('name', 'asc');

        return $this->paginated($query->paginate($perPage));
    }

    /** Contacts ordered by engagement score, with tier and what the score is made of. */
    public function ranking(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 20), 1), 100);
        $tier = $request->input('tier');

        $rows = $this->scopedToUser(Contact::query())
            ->where('status', '!=', 'no-whatsapp')
            ->when(in_array($tier, ['hot', 'warm', 'cold', 'frozen'], true), fn ($q) => $q->tier($tier))
            ->orderByDesc('score')->orderBy('name')->limit($limit)->get()
            ->map(fn (Contact $c) => [
                'id' => $c->id, 'name' => $c->name, 'contact' => $c->contact, 'status' => $c->status,
                'score' => (int) $c->score, 'tier' => $c->scoreTier(), 'score_locked' => (bool) $c->score_locked,
                'breakdown' => $c->score_breakdown, 'score_updated_at' => optional($c->score_updated_at)->toIso8601String(),
            ]);

        return $this->success($rows);
    }

    /** Recalculates the automatic score of one contact now. */
    public function rescore(Contact $contact)
    {
        $this->authorizeOwner($contact);
        $r = app(\App\Services\Contacts\ContactScorer::class)->recalculate($contact);

        return $r
            ? $this->success(['id' => $contact->id] + $r, 'Score recalculated.')
            : $this->success(['id' => $contact->id, 'score' => (int) $contact->score, 'score_locked' => true], 'Score is set manually; not recalculated.');
    }

    public function store(Request $request)
    {
        $data = $request->validate(array_merge(Contact::$rules, [
            'email' => 'nullable|email',
            'status' => 'nullable|in:ativo,inativo,no-whatsapp',
        ]));

        app(\App\Services\Billing\Entitlements::class)->authorizeUsage(Auth::user(), 'contacts');

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
            'score' => 'nullable|integer|between:0,100',
            'score_locked' => 'nullable|boolean', // false hands the score back to the automatic calculation
        ]));

        $contact->update($data);

        return $this->success($contact->fresh(), 'Contact updated.');
    }

    /** The contact with its linked channel identities (Telegram / Instagram / TikTok) and their conversations. */
    public function channels(Contact $contact)
    {
        $this->authorizeOwner($contact);

        $group = ContactLinks::group($contact);
        $chats = ContactLinks::chats($contact);

        return $this->success([
            'root_id' => ContactLinks::root($contact)->id,
            'contacts' => $group->map(fn (Contact $c) => [
                'id' => $c->id, 'name' => $c->name, 'contact' => $c->contact,
                'channel' => ContactLinks::channelOf($c), 'linked_contact_id' => $c->linked_contact_id,
            ])->values(),
            'chats' => $chats->map(fn ($c) => [
                'id' => $c->id, 'channel' => $c->instance ? $c->instance->channelKey() : 'whatsapp',
                'instance_id' => $c->instance_id, 'unread' => (int) $c->unread_count,
                'last_message_at' => optional($c->last_message_at)->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Ties a Telegram/Instagram/TikTok contact to a WhatsApp contact (either order). */
    public function link(Request $request, Contact $contact)
    {
        $this->authorizeOwner($contact);
        $data = $request->validate(['linked_contact_id' => 'required|integer']);
        $other = $this->scopedToUser(Contact::query())->findOrFail($data['linked_contact_id']);

        try {
            ContactLinks::link($contact, $other);
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(['group' => ContactLinks::group($contact->fresh())->pluck('id')->values()], 'Contacts linked.');
    }

    public function unlink(Contact $contact)
    {
        $this->authorizeOwner($contact);
        ContactLinks::unlink($contact);

        return $this->success(['id' => $contact->id, 'linked_contact_id' => $contact->fresh()->linked_contact_id], 'Contact unlinked.');
    }

    /** Runs the "messages never delivered" check for the caller's contacts (dry_run lists without deactivating). */
    public function checkUnresponsive(Request $request)
    {
        $data = $request->validate(['hours' => 'nullable|integer|min:1|max:8760', 'dry_run' => 'nullable|boolean']);

        $r = app(UnresponsiveContacts::class)->run($data['hours'] ?? null, $request->boolean('dry_run'), Auth::id());

        return $this->success($r + ['dry_run' => $request->boolean('dry_run')]);
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

        $import = new ContactsImport;
        Excel::import($import, $request->file('file'));

        return $this->success(['skipped_by_plan_limit' => $import->skipped], 'Contacts imported.');
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
