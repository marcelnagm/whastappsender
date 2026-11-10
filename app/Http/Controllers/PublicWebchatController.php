<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use App\Services\Webchat\Webchat;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Public side of the website chat widget: the JS loader, the widget settings, sending and reading messages (no login; the widget key is the address). */
class PublicWebchatController extends Controller
{
    /** GET /w/{key}.js: the one-line import the site owner pastes */
    public function script(string $key)
    {
        $i = $this->find($key);
        abort_unless($i && Webchat::config($i)['active'], 404);

        $js = str_replace(['__API__', '__KEY__'], [rtrim(url('/api/public/webchat/' . $i->instance_name), '/'), $i->instance_name], file_get_contents(resource_path('js-embed/webchat.js')));

        return response($js, 200, ['Content-Type' => 'application/javascript; charset=UTF-8', 'Cache-Control' => 'public, max-age=300', 'Access-Control-Allow-Origin' => '*']);
    }

    /** GET /api/public/webchat/{key}: what the widget needs to draw itself */
    public function definition(Request $request, string $key)
    {
        $i = $this->usable($request, $key);
        if (!$i) {
            return response()->json(['message' => 'Not available'], 403);
        }
        $c = Webchat::config($i);

        return response()->json([
            'title' => $c['title'] ?: ($i->name ?: __('Chat with us')),
            'welcome' => $c['welcome'] ?: __('Hi! How can we help you?'),
            'color' => Webchat::color($c['color']),
            'ask_contact' => (bool) $c['ask_contact'],
            'texts' => ['placeholder' => __('Type your message...'), 'send' => __('Send'), 'name' => __('Your name'), 'email' => __('Your e-mail (optional)'),
                'open' => __('Open chat'), 'close' => __('Close'), 'error' => __('Could not send. Try again.')],
        ]);
    }

    /** POST /api/public/webchat/{key}/messages */
    public function send(Request $request, string $key, Webchat $chat)
    {
        $i = $this->usable($request, $key);
        if (!$i) {
            return response()->json(['message' => 'Not available'], 403);
        }
        if (trim((string) $request->input('_hp', '')) !== '') {
            return response()->json(['ok' => true]); // honeypot: bots fill hidden fields
        }
        $d = $request->validate([
            'visitor' => ['required', 'regex:/^[a-f0-9]{32}$/'], 'text' => 'required|string|max:' . Webchat::MAX_TEXT, 'name' => 'nullable|string|max:80',
            'email' => 'nullable|string|max:190', 'page' => 'nullable|string|max:300', 'client_id' => 'nullable|string|max:64',
        ]);
        $m = $chat->receive($i, $d['visitor'], $d['text'], $d['name'] ?? null, $d['email'] ?? null, $d['page'] ?? null, $d['client_id'] ?? null);

        return response()->json(['ok' => true, 'id' => $m?->id]);
    }

    /** GET /api/public/webchat/{key}/messages?visitor=&after= */
    public function messages(Request $request, string $key, Webchat $chat)
    {
        $i = $this->usable($request, $key);
        if (!$i || !Webchat::validVisitor($request->query('visitor'))) {
            return response()->json(['message' => 'Not available'], 403);
        }

        return response()->json(['messages' => $chat->thread($i, (string) $request->query('visitor'), (int) $request->query('after', 0))]);
    }

    private function find(string $key): ?Instance
    {
        return Instance::where('channel', 'webchat')->where('instance_name', $key)->first();
    }

    /** The widget exists, is switched on and is shown on an allowed site. */
    private function usable(Request $request, string $key): ?Instance
    {
        $i = $this->find($key);
        if (!$i || !Webchat::config($i)['active']) {
            return null;
        }
        $allowed = Webchat::origins($i);
        if ($allowed) {
            $host = Str::lower((string) parse_url((string) ($request->headers->get('Origin') ?: $request->headers->get('Referer')), PHP_URL_HOST));
            $ok = false;
            foreach ($allowed as $d) {
                if ($host !== '' && ($host === $d || str_ends_with($host, '.' . $d))) {
                    $ok = true;
                }
            }
            if (!$ok) {
                return null;
            }
        }

        return $i;
    }
}
