<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessContactFormSubmissionJob;
use App\Models\ContactForm;
use App\Models\ContactFormSubmission;
use App\Services\Forms\ContactFormFields;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Public side of the embeddable contact form: the JS loader, the form definition and the submission endpoint (no login, CORS open, spam-protected). */
class PublicFormController extends Controller
{
    /** GET /f/{key}.js: the one-line import the site owner pastes */
    public function script(string $key)
    {
        $form = ContactForm::where('public_key', $key)->where('active', true)->first();
        abort_unless($form, 404);

        $js = str_replace(
            ['__API__', '__KEY__'],
            [rtrim(url('/api/public/forms/' . $form->public_key), '/'), $form->public_key],
            file_get_contents(resource_path('js-embed/contact-form.js'))
        );

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** GET /api/public/forms/{key}: what the script needs to draw the form */
    public function definition(Request $request, string $key)
    {
        $form = $this->find($key);
        if ($this->forbidden($request, $form)) {
            return response()->json(['message' => 'Not available'], 403);
        }

        return response()->json([
            'title' => $form->title,
            'description' => $form->description,
            'submit_label' => $form->submit_label ?: __('Send'),
            'accent_color' => $this->color($form->accent_color),
            'sending' => __('Sending...'),
            'consent' => $form->consent_text,
            'consent_error' => __('You must accept to continue.'),
            'fields' => array_map(fn ($f) => [
                'key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => (bool) $f['required'],
                'options' => $f['options'] ?? [], 'placeholder' => $f['placeholder'] ?? '',
            ], (array) $form->fields),
        ]);
    }

    /** POST /api/public/forms/{key} */
    public function submit(Request $request, string $key)
    {
        $form = $this->find($key);
        if ($this->forbidden($request, $form)) {
            return response()->json(['message' => 'Not available'], 403);
        }

        $ok = ['ok' => true, 'message' => $form->success_message ?: __('Thank you! We will contact you shortly.'), 'redirect' => $form->redirect_url ?: null];

        // hidden field humans never fill: bots do. Pretend it worked, store nothing
        if (trim((string) $request->input('_hp', '')) !== '') {
            return response()->json($ok);
        }

        $values = ContactFormFields::validateSubmission($form, $request->all()); // 422 with per-field errors

        // the consent checkbox: required when the form has a consent text, and the exact text accepted is kept as proof
        if ($form->consent_text && !filter_var($request->input('_consent'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(['message' => __('You must accept to continue.'), 'errors' => ['_consent' => [__('You must accept to continue.')]]], 422);
        }

        $s = ContactFormSubmission::create([
            'contact_form_id' => $form->id,
            'user_id' => $form->user_id,
            'data' => $values,
            'origin' => Str::limit((string) ($request->headers->get('Origin') ?: $request->headers->get('Referer')), 250, ''),
            'ip' => $request->ip(),
            'status' => 'pending',
            'consent_text' => $form->consent_text,
        ]);
        $form->increment('submissions_count');

        ProcessContactFormSubmissionJob::dispatch($s->id)->onQueue('default');

        return response()->json($ok);
    }

    private function find(string $key): ContactForm
    {
        return ContactForm::where('public_key', $key)->where('active', true)->firstOrFail();
    }

    /** With "allowed sites" set, only those domains (and their subdomains) may show/submit the form */
    private function forbidden(Request $request, ContactForm $form): bool
    {
        $allowed = $form->origins();
        if (!$allowed) {
            return false;
        }
        $from = $request->headers->get('Origin') ?: $request->headers->get('Referer');
        $host = Str::lower((string) parse_url((string) $from, PHP_URL_HOST));
        foreach ($allowed as $d) {
            if ($host !== '' && ($host === $d || str_ends_with($host, '.' . $d))) {
                return false;
            }
        }

        return true;
    }

    private function color(?string $c): string
    {
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $c) ? $c : '#0d6efd';
    }
}
