<?php

namespace App\Http\Controllers;

use App\Models\AiMessage;
use App\Models\AiProfile;
use App\Services\AI\AgentResponder;
use App\Services\AI\Llm\LlmClient;
use App\Services\AI\Llm\LlmException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The AI section: agents (provider + model + prompt) and the number each one answers for. */
class AiSettingsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $profiles = AiProfile::with('instance')->where('user_id', $user->id)->orderByRaw('instance_id is null desc')->orderBy('id')->get();
        $errors = AiMessage::query()
            ->join('ai_sessions', 'ai_sessions.id', '=', 'ai_messages.ai_session_id')
            ->where('ai_sessions.user_id', $user->id)
            ->where('ai_messages.status', 'error')
            ->orderByDesc('ai_messages.id')
            ->limit(5)
            ->get(['ai_messages.provider', 'ai_messages.model', 'ai_messages.error', 'ai_messages.created_at']);

        return view('ai.index', [
            'profiles' => $profiles,
            'failures' => $errors,
            'enabled' => (bool) $user->ai_enabled && $user->ai_mode === 'auto',
        ]);
    }

    /** Master switch for the AI replies of the whole account. */
    public function toggle(Request $request)
    {
        $on = $request->boolean('enabled');
        $request->user()->forceFill(['ai_enabled' => $on, 'ai_mode' => $on ? 'auto' : 'off'])->save();

        return back()->with('success', $on ? __('AI replies are on.') : __('AI replies are off.'));
    }

    public function create(Request $request)
    {
        return view('ai.form', $this->formData($request, new AiProfile([
            'enabled' => true, 'provider' => 'groq', 'model' => 'llama-3.3-70b-versatile', 'temperature' => 0.7, 'max_tokens' => 1024,
        ])));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request, null);
        $profile = new AiProfile($data + ['user_id' => $request->user()->id]);
        $profile->save();

        return redirect()->route('ai.index')->with('success', __('Agent saved.'));
    }

    public function edit(Request $request, AiProfile $profile)
    {
        $this->authorizeProfile($request, $profile);

        return view('ai.form', $this->formData($request, $profile));
    }

    public function update(Request $request, AiProfile $profile)
    {
        $this->authorizeProfile($request, $profile);
        $profile->fill($this->validated($request, $profile))->save();

        return redirect()->route('ai.index')->with('success', __('Agent saved.'));
    }

    public function destroy(Request $request, AiProfile $profile)
    {
        $this->authorizeProfile($request, $profile);
        $profile->delete();

        return redirect()->route('ai.index')->with('success', __('Agent removed.'));
    }

    /** "Test connection": one tiny request with the values on the form (the saved key is reused when the field is empty). */
    public function test(Request $request): JsonResponse
    {
        $existing = $request->filled('profile_id')
            ? AiProfile::where('user_id', $request->user()->id)->find($request->input('profile_id'))
            : null;

        $data = $request->validate($this->rules($request->user()->id, $existing));
        $profile = new AiProfile($data);
        if (trim((string) ($data['api_key'] ?? '')) === '' && $existing) {
            $profile->api_key = $existing->api_key;
        }
        $profile->max_tokens = 40;

        $started = microtime(true);
        try {
            $result = app(LlmClient::class)->chat($profile, 'You are a connection test. Answer with one short word.', [['role' => 'user', 'content' => 'Reply with the word OK.']]);
        } catch (LlmException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'reply' => mb_substr($result['reply'], 0, 80), 'ms' => (int) round((microtime(true) - $started) * 1000)]);
    }

    // ---------------------------------------------------------------------

    private function formData(Request $request, AiProfile $profile): array
    {
        return [
            'profile' => $profile,
            'instances' => $request->user()->instances()->orderBy('id')->get(),
            'providers' => collect(config('ai.providers'))->map(fn ($p, $key) => [
                'key' => $key,
                'label' => $p['label'],
                'models' => $p['models'] ?? [],
                'editable_url' => (bool) ($p['editable_url'] ?? false),
                'url_required' => (bool) ($p['url_required'] ?? false),
                'needs_key' => (bool) ($p['needs_key'] ?? true),
                'base_url' => $p['base_url'] ?? '',
                'docs' => $p['docs'] ?? null,
                'platform_key' => !empty($p['key_env']) && trim((string) env($p['key_env'])) !== '',
            ])->values(),
            'defaultPrompt' => AgentResponder::DEFAULT_PROMPT,
            'hoursDefaults' => config('ai.business_hours'),
        ];
    }

    private function rules(int $userId, ?AiProfile $current): array
    {
        return [
            'name' => 'required|string|max:80',
            'provider' => ['required', Rule::in(array_keys(config('ai.providers')))],
            'model' => 'required|string|max:120',
            'api_key' => 'nullable|string|max:500',
            'base_url' => 'nullable|url|max:255',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'max_tokens' => 'nullable|integer|min:50|max:8000',
            'system_prompt' => 'nullable|string|max:8000',
            'business_days' => 'nullable|array',
            'business_days.*' => 'integer|between:1,7',
            'business_start' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'business_end' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'timezone' => ['nullable', Rule::in(\DateTimeZone::listIdentifiers())],
            'instance_id' => ['nullable', Rule::exists('instances', 'id')->where('user_id', $userId)],
        ];
    }

    private function validated(Request $request, ?AiProfile $current): array
    {
        $userId = $request->user()->id;
        $data = $request->validate($this->rules($userId, $current));
        $cfg = config("ai.providers.{$data['provider']}");

        if (!empty($cfg['url_required']) && empty($data['base_url'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['base_url' => __('Endpoint URL is required for this provider.')]);
        }

        // one agent per number (and one "any instance" agent)
        $instanceId = $data['instance_id'] ?? null;
        $duplicate = AiProfile::where('user_id', $userId)
            ->when($instanceId, fn ($q) => $q->where('instance_id', $instanceId), fn ($q) => $q->whereNull('instance_id'))
            ->when($current, fn ($q) => $q->where('id', '!=', $current->id))
            ->exists();
        if ($duplicate) {
            throw \Illuminate\Validation\ValidationException::withMessages(['instance_id' => __('There is already an agent for this instance.')]);
        }

        $out = [
            'name' => trim($data['name']),
            'instance_id' => $instanceId ?: null,
            'enabled' => $request->boolean('enabled'),
            'provider' => $data['provider'],
            'model' => trim($data['model']),
            'base_url' => !empty($cfg['editable_url']) ? (trim((string) ($data['base_url'] ?? '')) ?: null) : null,
            'temperature' => isset($data['temperature']) ? (float) $data['temperature'] : 0.7,
            'max_tokens' => isset($data['max_tokens']) ? (int) $data['max_tokens'] : 1024,
            'system_prompt' => trim((string) ($data['system_prompt'] ?? '')) ?: null,
            'business_hours_only' => $request->boolean('business_hours_only'),
            'business_days' => !empty($data['business_days']) ? implode(',', collect($data['business_days'])->map('intval')->unique()->sort()->values()->all()) : null,
            'business_start' => $data['business_start'] ?? null,
            'business_end' => $data['business_end'] ?? null,
            'timezone' => $data['timezone'] ?? null,
        ];

        // empty key on edit = keep the saved one; "clear_key" removes it
        if ($request->boolean('clear_key')) {
            $out['api_key'] = null;
        } elseif (trim((string) ($data['api_key'] ?? '')) !== '') {
            $out['api_key'] = trim($data['api_key']);
        } elseif (!$current) {
            $out['api_key'] = null;
        }

        return $out;
    }

    private function authorizeProfile(Request $request, AiProfile $profile): void
    {
        abort_unless((int) $profile->user_id === (int) $request->user()->id, 403);
    }
}
