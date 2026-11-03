<?php

namespace App\Http\Controllers;

use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Models\Instance;
use App\Services\Forms\ContactFormFields;
use Illuminate\Http\Request;

/** Where the account owner builds the embeddable contact forms and sees what came in. */
class ContactFormController extends Controller
{
    public const CHANNELS = ['whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok', 'email'];

    public function index(Request $request)
    {
        return view('forms.index', [
            'forms' => ContactForm::where('user_id', $request->user()->id)->orderByDesc('id')->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('forms.edit', $this->editData($request, new ContactForm(['fields' => ContactFormFields::defaults(), 'ai_reply' => true, 'active' => true])));
    }

    public function store(Request $request)
    {
        $form = new ContactForm(['user_id' => $request->user()->id]);
        $form->fill($this->validated($request))->save();

        return redirect()->route('forms.edit', $form->id)->with('success', __('Form saved. Paste the code on your site.'));
    }

    public function edit(Request $request, int $form)
    {
        return view('forms.edit', $this->editData($request, $this->mine($request, $form)));
    }

    public function update(Request $request, int $form)
    {
        $f = $this->mine($request, $form);
        $f->fill($this->validated($request))->save();

        return redirect()->route('forms.edit', $f->id)->with('success', __('Form saved.'));
    }

    public function destroy(Request $request, int $form)
    {
        $f = $this->mine($request, $form);
        ContactFormSubmission::where('contact_form_id', $f->id)->delete();
        $f->delete();

        return redirect()->route('forms.index')->with('success', __('Form removed.'));
    }

    public function submissions(Request $request, int $form)
    {
        $f = $this->mine($request, $form);

        return view('forms.submissions', [
            'form' => $f,
            'submissions' => ContactFormSubmission::with('contact')->where('contact_form_id', $f->id)->orderByDesc('id')->paginate(30),
        ]);
    }

    private function mine(Request $request, int $id): ContactForm
    {
        return ContactForm::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function editData(Request $request, ContactForm $form): array
    {
        return [
            'form' => $form,
            'instances' => Instance::where('user_id', $request->user()->id)->where(fn ($q) => $q->whereNull('channel')->orWhere('channel', 'whatsapp'))->orderBy('name')->get(),
            'types' => ContactFormFields::TYPES,
            'maps' => ContactFormFields::MAPS,
            'channels' => self::CHANNELS,
        ];
    }

    private function validated(Request $request): array
    {
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'title' => 'nullable|string|max:160',
            'description' => 'nullable|string|max:1000',
            'submit_label' => 'nullable|string|max:60',
            'success_message' => 'nullable|string|max:500',
            'redirect_url' => 'nullable|url|max:500',
            'accent_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'allowed_origins' => 'nullable|string|max:2000',
            'instance_id' => 'nullable|integer',
            'reply_channels' => 'nullable|array',
            'reply_channels.*' => 'in:' . implode(',', self::CHANNELS),
            'fields' => 'required|array',
        ]);

        // the chosen number must be the owner's own
        $instance = $v['instance_id'] ?? null;
        if ($instance && !Instance::where('user_id', $request->user()->id)->whereKey($instance)->exists()) {
            $instance = null;
        }

        return [
            'name' => $v['name'],
            'title' => $v['title'] ?? null,
            'description' => $v['description'] ?? null,
            'submit_label' => $v['submit_label'] ?? null,
            'success_message' => $v['success_message'] ?? null,
            'redirect_url' => $v['redirect_url'] ?? null,
            'accent_color' => $v['accent_color'] ?? null,
            'allowed_origins' => $v['allowed_origins'] ?? null,
            'instance_id' => $instance,
            'reply_channels' => $v['reply_channels'] ?? [],
            'ai_reply' => $request->boolean('ai_reply'),
            'active' => $request->boolean('active'),
            'fields' => ContactFormFields::normalize($v['fields']),
        ];
    }
}
