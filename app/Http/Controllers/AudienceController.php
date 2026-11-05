<?php

namespace App\Http\Controllers;

use App\Models\ContactField;
use App\Models\ContactSegment;
use App\Services\Contacts\ContactFilter;
use App\Services\Contacts\CustomFields;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The account's own contact fields and the segments (saved filters) built on contact data. */
class AudienceController extends Controller
{
    public function __construct(private CustomFields $fields, private ContactFilter $filter)
    {
    }

    public function index(Request $request)
    {
        $uid = $request->user()->id;
        $segments = ContactSegment::where('user_id', $uid)->orderBy('name')->get()
            ->each(fn ($s) => $s->setAttribute('size', $this->filter->query($uid, (array) $s->filters)->count()));
        $catalog = [];
        foreach ($this->filter->catalog($uid) as $field => $type) {
            $label = match ($field) {
                'score' => __('Score'), 'consent_status' => __('Consent'), 'has_email' => __('Has e-mail'), 'created' => __('Created on'),
                default => optional($this->fields->for($uid)->firstWhere('key', substr($field, 7)))->label ?? $field,
            };
            $catalog[] = ['field' => $field, 'type' => $type, 'label' => $label, 'ops' => ContactFilter::OPS[$type],
                'options' => $field === 'consent_status' ? ['unknown', 'granted', 'revoked'] : (str_starts_with($field, 'custom:') ? (array) optional($this->fields->for($uid)->firstWhere('key', substr($field, 7)))->options : [])];
        }

        return view('audience.index', [
            'fields' => $this->fields->for($uid), 'segments' => $segments, 'catalog' => $catalog, 'types' => ContactField::TYPES,
        ]);
    }

    public function storeField(Request $request)
    {
        $uid = $request->user()->id;
        $data = $request->validate([
            'label' => 'required|string|max:60',
            'type' => ['required', Rule::in(ContactField::TYPES)],
            'options' => 'nullable|string|max:500',
        ]);
        $key = CustomFields::slug($data['label']);
        if ($key === '' || in_array($key, ['name', 'contact', 'email', 'score', 'nome', 'telefone'], true)) {
            return back()->withErrors(['label' => __('Choose another name for the field.')]);
        }
        if (ContactField::where('user_id', $uid)->where('key', $key)->exists()) {
            return back()->withErrors(['label' => __('A field with this name already exists.')]);
        }
        if (ContactField::where('user_id', $uid)->count() >= 30) {
            return back()->withErrors(['label' => __('Limit of 30 fields reached.')]);
        }
        $options = $data['type'] === 'select'
            ? array_values(array_unique(array_filter(array_map(fn ($o) => mb_substr(trim($o), 0, 60), preg_split('/[,\n]+/', (string) ($data['options'] ?? '')))))) : null;
        if ($data['type'] === 'select' && !$options) {
            return back()->withErrors(['options' => __('Type the choices, separated by commas.')]);
        }

        ContactField::create(['user_id' => $uid, 'key' => $key, 'label' => $data['label'], 'type' => $data['type'], 'options' => $options,
            'position' => (int) ContactField::where('user_id', $uid)->max('position') + 1]);

        return back()->with('success', __('Field created.'));
    }

    public function destroyField(Request $request, int $field)
    {
        $uid = $request->user()->id;
        $f = ContactField::where('user_id', $uid)->findOrFail($field);
        // values stay on the contacts (harmless, ignored); segments using the field stop matching it
        $f->delete();
        foreach (ContactSegment::where('user_id', $uid)->get() as $s) {
            $s->update(['filters' => $this->filter->normalize($uid, (array) $s->filters)]);
        }

        return back()->with('success', __('Field removed.'));
    }

    public function preview(Request $request)
    {
        $uid = $request->user()->id;
        $conditions = $this->filter->normalize($uid, (array) $request->input('filters'));

        return response()->json(['conditions' => count($conditions), 'count' => $this->filter->query($uid, $conditions)->count()]);
    }

    public function storeSegment(Request $request)
    {
        $uid = $request->user()->id;
        $data = $request->validate(['name' => 'required|string|max:80']);
        $conditions = $this->filter->normalize($uid, (array) $request->input('filters'));
        if (!$conditions) {
            return back()->withErrors(['name' => __('Add at least one valid condition.')])->withInput();
        }
        if (ContactSegment::where('user_id', $uid)->count() >= 50) {
            return back()->withErrors(['name' => __('Limit of 50 segments reached.')]);
        }
        ContactSegment::create(['user_id' => $uid, 'name' => $data['name'], 'filters' => $conditions, 'created_by' => TeamContext::actor($request)->id]);

        return back()->with('success', __('Segment saved.'));
    }

    public function destroySegment(Request $request, int $segment)
    {
        ContactSegment::where('user_id', $request->user()->id)->findOrFail($segment)->delete();

        return back()->with('success', __('Segment removed.'));
    }

    public function export(Request $request, int $segment)
    {
        $uid = $request->user()->id;
        $seg = ContactSegment::where('user_id', $uid)->findOrFail($segment);
        $fields = $this->fields->for($uid);

        return response()->streamDownload(function () use ($uid, $seg, $fields) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['name', 'contact', 'email', 'score', 'consent'], $fields->pluck('label')->all()));
            $this->filter->query($uid, (array) $seg->filters)->orderBy('contacts.id')->chunk(500, function ($rows) use ($out, $fields) {
                foreach ($rows as $c) {
                    $row = [$c->name, $c->contact, $c->email, $c->score, $c->consent_status];
                    foreach ($fields as $f) {
                        $v = ($c->custom ?? [])[$f->key] ?? '';
                        $row[] = $v === '' ? '' : CustomFields::show($f, $v);
                    }
                    // spreadsheet formula injection
                    fputcsv($out, array_map(fn ($x) => is_string($x) && preg_match('/^[=+\-@]/', $x) ? "'" . $x : $x, $row));
                }
            });
            fclose($out);
        }, 'segment-' . $seg->id . '.csv', ['Content-Type' => 'text/csv']);
    }
}
