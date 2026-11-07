<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use Illuminate\Http\Request;

/** Companies: groups of contacts and deals with their combined value. */
class CompaniesController extends Controller
{
    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $q = trim((string) $request->query('q', ''));
        $companies = Company::where('user_id', $uid)->withCount('contacts')
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('domain', 'like', "%{$q}%")))
            ->orderBy('name')->paginate(30)->withQueryString();
        $won = Deal::where('user_id', $uid)->where('status', 'won')->whereIn('company_id', $companies->pluck('id'))->selectRaw('company_id, sum(value) as total')->groupBy('company_id')->pluck('total', 'company_id');

        return view('companies.index', compact('companies', 'won', 'q'));
    }

    public function store(Request $request)
    {
        Company::create($this->data($request) + ['user_id' => $request->user()->id]);

        return back()->with('success', __('Company saved.'));
    }

    public function show(Request $request, int $company)
    {
        $co = $this->owned($request, $company);
        $contacts = Contact::where('user_id', $co->user_id)->where('company_id', $co->id)->orderBy('name')->get();
        $deals = Deal::with('stage')->where('user_id', $co->user_id)->where('company_id', $co->id)->latest('id')->get();
        $summary = ['open' => $deals->where('status', 'open')->sum('value'), 'won' => $deals->where('status', 'won')->sum('value')];
        $free = Contact::where('user_id', $co->user_id)->whereNull('company_id')->orderBy('name')->limit(300)->get(['id', 'name']);

        return view('companies.show', compact('co', 'contacts', 'deals', 'summary', 'free'));
    }

    public function update(Request $request, int $company)
    {
        $this->owned($request, $company)->update($this->data($request));

        return back()->with('success', __('Company saved.'));
    }

    public function destroy(Request $request, int $company)
    {
        $co = $this->owned($request, $company);
        Contact::where('company_id', $co->id)->update(['company_id' => null]);
        Deal::where('company_id', $co->id)->update(['company_id' => null]);
        $co->delete();

        return redirect()->route('companies.index')->with('success', __('Company removed.'));
    }

    public function attach(Request $request, int $company)
    {
        $co = $this->owned($request, $company);
        $data = $request->validate(['contact_id' => 'required|integer']);
        $c = Contact::where('user_id', $co->user_id)->findOrFail($data['contact_id']);
        $c->update(['company_id' => $co->id]);

        return back()->with('success', __('Contact added.'));
    }

    public function detach(Request $request, int $company, int $contact)
    {
        $co = $this->owned($request, $company);
        Contact::where('user_id', $co->user_id)->where('company_id', $co->id)->findOrFail($contact)->update(['company_id' => null]);

        return back();
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:160', 'domain' => 'nullable|string|max:160', 'phone' => 'nullable|string|max:40',
            'industry' => 'nullable|string|max:80', 'notes' => 'nullable|string|max:5000',
        ]);
    }

    private function owned(Request $request, int $id): Company
    {
        return Company::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
