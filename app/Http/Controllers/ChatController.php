<?php

namespace App\Http\Controllers;

use App\Jobs\ResolveLidChatJob;
use App\Jobs\SendChatMessageJob;
use App\Jobs\SyncChatHistoryJob;
use App\Models\AiSession;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Instance;
use App\Services\Chat\ChatAvatarService;
use App\Services\Chat\ChatMessageRecorder;
use App\Services\Chat\ChatSignature;
use App\Services\Chat\EvolutionChatClient;
use App\Services\Chat\HistorySyncStatus;
use App\Services\Chat\LidResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * WhatsApp Web-style chat. The browser only talks to Laravel; Laravel talks to the Evolution API
 * (instance name = WhatsApp session number) and receives events through the webhook.
 */
class ChatController extends Controller
{
    public function __construct(
        private EvolutionChatClient $evolution,
        private ChatMessageRecorder $recorder,
        private ChatAvatarService $avatars
    ) {
    }

    // ---------------------------------------------------------------------
    // Page
    // ---------------------------------------------------------------------

    public function index(Request $request)
    {
        $instances = $request->user()->instances()->orderBy('id')->get();
        $current = $instances->firstWhere('id', (int) $request->query('instance')) ?? $instances->first();

        // First time this number is opened in the chat: bring in the conversations Evolution already stores
        if ($current && $current->isWhatsapp() && !ChatMessage::where('instance_id', $current->id)->exists() && Cache::add("chat-history-sync:{$current->id}", 1, 3600)) {
            HistorySyncStatus::queued($current->id);
            SyncChatHistoryJob::dispatch($current->id)->onQueue('default');
        }

        $signature = ChatSignature::normalize($request->user()->chat_signature);

        return view('chat.index', compact('instances', 'current', 'signature'));
    }

    // ---------------------------------------------------------------------
    // Chats
    // ---------------------------------------------------------------------

    /**
     * Chats identified by an opaque "@lid" are converted to the phone number: at once when we already know it,
     * otherwise in the background (at most one attempt per chat every 10 minutes).
     */
    private function fixLidChats(Instance $instance): void
    {
        $lids = Chat::where('instance_id', $instance->id)->where('remote_jid', 'like', '%@lid')->limit(10)->get();
        if ($lids->isEmpty()) {
            return;
        }

        $resolver = app(LidResolver::class);
        foreach ($lids as $chat) {
            if ($resolver->fixChat($chat, false)) {
                continue;
            }
            if (Cache::add("lid-chat:{$chat->id}", 1, 600)) {
                ResolveLidChatJob::dispatch($chat->id)->onQueue('default');
            }
        }
    }

    public function chats(Request $request): JsonResponse
    {
        $instance = $this->ownedInstance((int) $request->query('instance'));
        $q = trim((string) $request->query('q', ''));
        $digits = preg_replace('/\D/', '', $q);

        $this->fixLidChats($instance);

        $chats = Chat::with('contact')
            ->where('user_id', Auth::id())
            ->where('instance_id', $instance->id)
            ->where('archived', false)
            ->whereNotNull('last_message_at')
            ->when($q !== '', function ($query) use ($q, $digits) {
                $query->where(function ($w) use ($q, $digits) {
                    $w->where('name', 'like', "%{$q}%");
                    if ($digits !== '') {
                        $w->orWhere('phone', 'like', "%{$digits}%");
                    }
                    $w->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$q}%"));
                });
            })
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        // Pictures are fetched in the background; they show up on the next poll
        $chats->filter(fn (Chat $c) => $this->avatars->isDue($c))->take(10)->each(fn (Chat $c) => $this->avatars->queueIfDue($c));

        return response()->json([
            'chats' => $chats->map(fn (Chat $c) => $this->chatToApi($c))->values(),
            'connected' => $instance->isConnected(),
        ]);
    }

    /**
     * Opens (or creates) a chat with a phone number typed by the agent.
     */
    public function openChat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'instance_id' => 'required|integer',
            'phone' => 'required|string|max:32',
        ]);

        $instance = $this->ownedInstance((int) $data['instance_id']);
        if (!$instance->isWhatsapp()) {
            return response()->json(['message' => __('Only WhatsApp can start a conversation. On this channel the customer must write first.')], 422);
        }
        $phone = preg_replace('/\D/', '', $data['phone']);

        if (strlen($phone) < 8 || strlen($phone) > 15) {
            return response()->json(['message' => __('Invalid phone number.')], 422);
        }

        // Asks Evolution for the canonical JID (handles the Brazilian 9th digit)
        $check = $this->evolution->checkNumber($instance->instance_name, $phone);
        if ($check && !$check['exists']) {
            return response()->json(['message' => __('Number is not on WhatsApp')], 422);
        }

        $jid = $check['jid'] ?? ($phone . '@s.whatsapp.net');
        $chat = $this->recorder->findOrCreateChat($instance, $jid);

        return response()->json(['chat' => $this->chatToApi($chat->load('contact'))]);
    }

    public function searchContacts(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $contacts = Contact::where('user_id', Auth::id())
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('name', 'like', "%{$q}%")
                        ->orWhere('contact', 'like', '%' . preg_replace('/\D/', '', $q) . '%');
                });
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'contact']);

        return response()->json([
            'contacts' => $contacts->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'phone' => $c->contactFormat()]),
        ]);
    }

    /** The name written in front of messages sent from the CRM. Empty / only spaces turns it off. */
    public function saveSignature(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => 'nullable|string|max:200']);
        $name = ChatSignature::normalize($data['name'] ?? null);

        $request->user()->forceFill(['chat_signature' => $name])->save();

        return response()->json([
            'name' => $name,
            'header' => ChatSignature::header($name),
        ]);
    }

    public function syncHistory(Request $request, Instance $instance): JsonResponse
    {
        abort_unless((int) $instance->user_id === (int) Auth::id(), 403);
        abort_if(!$instance->isWhatsapp(), 422, __('History import is only available for WhatsApp.'));

        if (!HistorySyncStatus::isBusy($instance->id)) {
            HistorySyncStatus::queued($instance->id);
            SyncChatHistoryJob::dispatch($instance->id)->onQueue('default');
        }

        return response()->json(HistorySyncStatus::get($instance->id), 202);
    }

    public function syncStatus(Instance $instance): JsonResponse
    {
        abort_unless((int) $instance->user_id === (int) Auth::id(), 403);

        return response()->json(HistorySyncStatus::get($instance->id));
    }

    // ---------------------------------------------------------------------
    // Messages
    // ---------------------------------------------------------------------

    /**
     * Initial page, older pages (before_id) and incremental updates (since).
     */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);

        $limit = (int) config('chat.page_size', 40);
        $query = ChatMessage::where('chat_id', $chat->id);
        $hasMore = false;
        $initial = false;

        if ($since = $request->query('since')) {
            try {
                $from = Carbon::parse($since)->setTimezone(config('app.timezone'))->subSeconds(2);
            } catch (\Throwable $e) {
                $from = now()->subMinute();
            }
            $rows = $query->where('updated_at', '>=', $from)->orderBy('id')->limit(200)->get();
        } else {
            if ($before = (int) $request->query('before_id')) {
                $query->where('id', '<', $before);
            } else {
                $initial = true;
            }

            $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
            $hasMore = $rows->count() > $limit;
            $rows = $rows->take($limit)->sortBy('id')->values();
        }

        if ($initial) {
            $this->avatars->queueIfDue($chat);
        }

        return response()->json([
            'messages' => $this->serialize($chat, $rows),
            'has_more' => $hasMore,
            'server_time' => now()->toIso8601String(),
            'chat' => $this->chatToApi($chat->fresh('contact')),
        ]);
    }

    public function send(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);

        $data = $request->validate([
            'text' => 'nullable|string|max:4096',
            'client_id' => 'nullable|string|max:64',
            'quoted_id' => 'nullable|integer',
            'as_voice' => 'nullable|boolean',
            'file' => 'nullable|file|max:' . (int) config('chat.max_upload_kb', 16384),
        ]);

        $text = trim((string) ($data['text'] ?? ''));
        $file = $request->file('file');

        if ($chat->instance->isInstagram() && !\App\Services\Instagram\InstagramWindow::isOpen($chat)) {
            return response()->json(['message' => \App\Services\Instagram\InstagramWindow::closedMessage()], 422);
        }

        if ($text === '' && !$file) {
            return response()->json(['message' => __('Type a message')], 422);
        }

        $signature = $request->user()->chat_signature;

        $attributes = [
            'chat_id' => $chat->id,
            'instance_id' => $chat->instance_id,
            'user_id' => $chat->user_id,
            'from_me' => true,
            'type' => 'text',
            // stored exactly as the customer receives it (signature included), so the echo from WhatsApp matches
            'body' => ChatSignature::apply($signature, $text),
            'status' => 'pending',
            'client_id' => $data['client_id'] ?? null,
            'message_timestamp' => now(),
        ];

        if ($file) {
            $mime = $this->normalizeMime($file->getClientMimeType() ?: $file->getMimeType());
            $type = $this->typeFromMime($mime, $request->boolean('as_voice'));
            $extension = strtolower($file->getClientOriginalExtension() ?: ($type === 'audio' ? 'ogg' : 'bin'));
            $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'bin';

            $path = $file->storeAs(
                config('chat.media_path', 'chat-media') . '/' . $chat->instance_id,
                Str::uuid() . '.' . $extension
            );

            // voice notes carry no text; every other media can carry the signature as (part of) its caption
            $attributes['body'] = $type === 'audio' ? null : ChatSignature::apply($signature, $text, true);

            $attributes = array_merge($attributes, [
                'type' => $type,
                'mimetype' => $mime,
                'file_name' => Str::limit($file->getClientOriginalName(), 200, ''),
                'media_path' => $path,
            ]);
        }

        if (!empty($data['quoted_id'])) {
            $quoted = ChatMessage::where('chat_id', $chat->id)->find($data['quoted_id']);
            if ($quoted && $quoted->message_id) {
                $attributes['quoted_message_id'] = $quoted->message_id;
                $attributes['quoted_preview'] = $this->previewOf($quoted);
            }
        }

        $message = ChatMessage::create($attributes);
        $this->recorder->touchChat($chat, $message, true);

        if (config('chat.pause_ai_on_reply') && $chat->contact_id) {
            AiSession::where('user_id', $chat->user_id)
                ->where('contact_id', $chat->contact_id)
                ->where('instance_id', $chat->instance_id)
                ->update(['human_handoff' => true]);
        }

        SendChatMessageJob::dispatch($message->id)->onQueue('default');

        return response()->json(['message' => $message->toApi()], 201);
    }

    public function retry(ChatMessage $message): JsonResponse
    {
        $this->authorizeMessage($message);

        if (!$message->from_me || $message->status !== 'error' || $message->message_id) {
            return response()->json(['message' => __('Nothing to retry.')], 422);
        }

        $message->update(['status' => 'pending', 'error' => null]);
        Chat::where('id', $message->chat_id)->where('last_message_id', $message->id)->update(['last_message_status' => 'pending']);

        SendChatMessageJob::dispatch($message->id)->onQueue('default');

        return response()->json(['message' => $message->fresh()->toApi()]);
    }

    public function react(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeMessage($message);
        $data = $request->validate(['emoji' => 'nullable|string|max:16']);

        if (!$message->message_id || $message->deleted || !$message->instance->isWhatsapp()) {
            return response()->json(['message' => __('Message not available for reactions.')], 422);
        }

        $emoji = (string) ($data['emoji'] ?? '');
        $chat = $message->chat;

        try {
            $this->evolution->sendReaction(
                $message->instance->instance_name,
                $chat->remote_jid,
                $message->message_id,
                (bool) $message->from_me,
                $emoji
            );
        } catch (\Throwable $e) {
            Log::warning('Chat reaction failed: ' . $e->getMessage());

            return response()->json(['message' => __('Could not send the reaction.')], 502);
        }

        $reactions = $message->reactions ?? [];
        if ($emoji === '') {
            unset($reactions['me']);
        } else {
            $reactions['me'] = $emoji;
        }
        $message->update(['reactions' => $reactions]);

        return response()->json(['message' => $message->toApi()]);
    }

    public function destroy(ChatMessage $message): JsonResponse
    {
        $this->authorizeMessage($message);

        if (!$message->from_me || !$message->message_id || $message->deleted || !$message->instance->isWhatsapp()) {
            return response()->json(['message' => __('This message cannot be deleted.')], 422);
        }

        try {
            $this->evolution->deleteForEveryone(
                $message->instance->instance_name,
                $message->chat->remote_jid,
                $message->message_id,
                true
            );
        } catch (\Throwable $e) {
            Log::warning('Chat delete failed: ' . $e->getMessage());

            return response()->json(['message' => __('Could not delete the message.')], 502);
        }

        $message->update(['deleted' => true, 'body' => null]);
        Chat::where('id', $message->chat_id)->where('last_message_id', $message->id)->update([
            'last_message_type' => 'deleted',
            'last_message_preview' => null,
        ]);

        return response()->json(['message' => $message->fresh()->toApi()]);
    }

    public function markRead(Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);

        $chat->update(['unread_count' => 0]);

        $unread = ChatMessage::where('chat_id', $chat->id)
            ->where('from_me', false)
            ->where('status', 'received')
            ->whereNotNull('message_id')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'message_id']);

        if ($unread->isNotEmpty()) {
            ChatMessage::whereIn('id', $unread->pluck('id'))->update(['status' => 'read']);

            if (config('chat.send_read_receipts', true) && $chat->instance->isWhatsapp()) {
                $keys = $unread->map(fn ($m) => [
                    'remoteJid' => $chat->remote_jid,
                    'fromMe' => false,
                    'id' => $m->message_id,
                ])->all();
                $instanceName = $chat->instance->instance_name;

                app()->terminating(function () use ($instanceName, $keys) {
                    try {
                        $this->evolution->markAsRead($instanceName, $keys);
                    } catch (\Throwable $e) {
                        Log::warning('Chat markAsRead failed: ' . $e->getMessage());
                    }
                });
            }
        }

        return response()->json(['status' => 'ok']);
    }

    public function presence(Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);

        if (!$chat->instance->isWhatsapp()) {
            $other = substr((string) $chat->remote_jid, 3); // "tg:<id>" / "ig:<id>"
            $client = $chat->instance->isInstagram()
                ? \App\Services\Instagram\InstagramClient::for($chat->instance)
                : \App\Services\Telegram\TelegramClient::for($chat->instance);
            app()->terminating(fn () => $client->typing($other));

            return response()->json(null, 204);
        }

        $instanceName = $chat->instance->instance_name;
        $number = $chat->sendNumber();

        app()->terminating(function () use ($instanceName, $number) {
            try {
                $this->evolution->sendPresence($instanceName, $number, 'composing', 3000);
            } catch (\Throwable $e) {
                // presence is cosmetic
            }
        });

        return response()->json(null, 204);
    }

    // ---------------------------------------------------------------------
    // Media
    // ---------------------------------------------------------------------

    /**
     * Locally stored profile picture of a conversation.
     */
    public function avatar(Chat $chat)
    {
        $this->authorizeChat($chat);
        abort_unless($chat->avatar_path && Storage::exists($chat->avatar_path), 404);

        return response()->file(Storage::path($chat->avatar_path), [
            'Content-Type' => ChatAvatarService::mimeForPath($chat->avatar_path),
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function media(ChatMessage $message)
    {
        $this->authorizeMessage($message);
        abort_unless($message->hasMedia(), 404);

        if (!$message->media_path || !Storage::exists($message->media_path)) {
            abort_unless($message->message_id && $message->instance->isWhatsapp(), 404);

            try {
                $data = $this->evolution->mediaBase64($message->instance->instance_name, $message->message_id);
            } catch (\Throwable $e) {
                Log::warning('Chat media download failed: ' . $e->getMessage());
                abort(404);
            }

            $base64 = (string) ($data['base64'] ?? '');
            $base64 = preg_replace('/^data:[^;]+;base64,/', '', $base64);
            $binary = $base64 !== '' ? base64_decode($base64, true) : false;
            abort_if($binary === false || $binary === '', 404);

            $mime = $this->normalizeMime($data['mimetype'] ?? $message->mimetype);
            $path = config('chat.media_path', 'chat-media') . '/' . $message->instance_id . '/' . $message->id . '.' . $this->extensionFor($mime);
            Storage::put($path, $binary);
            $message->update(['media_path' => $path, 'mimetype' => $mime]);
        }

        $mime = $this->normalizeMime($message->mimetype);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ];

        // Only types a browser can show safely are rendered inline; everything else is a download.
        // (The mimetype of inbound media is chosen by the sender.)
        if ($this->isInlineSafe($mime)) {
            return response()->file(Storage::path($message->media_path), $headers + ['Content-Type' => $mime]);
        }

        return response()->download(
            Storage::path($message->media_path),
            $message->file_name ?: ('file-' . $message->id),
            $headers + ['Content-Type' => 'application/octet-stream']
        );
    }

    // ---------------------------------------------------------------------
    // CRM side panel
    // ---------------------------------------------------------------------

    public function contactInfo(Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        $chat->load('contact');
        $contact = $chat->contact;

        $session = $contact
            ? AiSession::where('user_id', $chat->user_id)
                ->where('contact_id', $contact->id)
                ->where('instance_id', $chat->instance_id)
                ->first()
            : null;

        return response()->json([
            'phone' => $chat->phone,
            'push_name' => $chat->name,
            'contact' => $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->contactFormat(),
                'email' => $contact->email,
                'status' => $contact->status,
                'score' => $contact->score,
                'ignore_me' => (bool) $contact->ignore_me,
                'url' => route('contacts.show', $contact->id),
                'edit_url' => route('contacts.edit', $contact->id),
            ] : null,
            'ai' => [
                'enabled' => (bool) Auth::user()->ai_enabled,
                'paused' => $session ? (bool) $session->human_handoff : false,
            ],
        ]);
    }

    public function saveContact(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
        ]);

        if ($chat->contact_id && ($contact = Contact::where('user_id', $chat->user_id)->find($chat->contact_id))) {
            $contact->update(['name' => $data['name'], 'email' => $data['email'] ?? $contact->email]);
        } else {
            if (!$chat->phone) {
                return response()->json(['message' => __('This chat has no phone number yet.')], 422);
            }

            $contact = Contact::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'contact' => $chat->phone,
                'user_id' => $chat->user_id,
                'status' => 'ativo',
            ]);
            $chat->update(['contact_id' => $contact->id]);
        }

        return $this->contactInfo($chat->fresh());
    }

    public function aiPause(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        $data = $request->validate(['paused' => 'required|boolean']);

        if (!$chat->contact_id) {
            return response()->json(['message' => __('Save this person to the CRM first.')], 422);
        }

        $session = AiSession::firstOrCreate(
            ['user_id' => $chat->user_id, 'contact_id' => $chat->contact_id, 'instance_id' => $chat->instance_id],
            ['status' => 'active', 'human_handoff' => false]
        );
        $session->update(['human_handoff' => (bool) $data['paused']]);

        return response()->json(['paused' => (bool) $session->human_handoff]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Manual merge: the agent tells which phone an unidentified (@lid) chat belongs to. The pair is stored, so the
     * chat is merged into the phone chat of the same number and every later message of that lid lands there.
     */
    public function linkPhone(Request $request, Chat $chat): JsonResponse
    {
        $this->authorizeChat($chat);
        abort_unless(!preg_match('/^(tg|ig):/', (string) $chat->remote_jid) && LidResolver::isLid($chat->remote_jid), 422, __('This conversation is already identified.'));

        $data = $request->validate(['phone' => 'required|string|max:30']);
        $phone = LidResolver::phoneFrom(preg_replace('/\D/', '', $data['phone']));
        if (!$phone) {
            return response()->json(['message' => __('Enter the phone number with country code and area code.')], 422);
        }

        $instance = Instance::findOrFail($chat->instance_id);
        $target = app(LidResolver::class)->learn($instance, $chat->remote_jid, $phone, 'manual');

        return response()->json(['chat' => $target ? $this->chatToApi($target) : null]);
    }

    private function ownedInstance(int $id): Instance
    {
        return Instance::where('id', $id)->where('user_id', Auth::id())->firstOrFail();
    }

    private function authorizeChat(Chat $chat): void
    {
        abort_unless((int) $chat->user_id === (int) Auth::id(), 403);
    }

    private function authorizeMessage(ChatMessage $message): void
    {
        abort_unless((int) $message->user_id === (int) Auth::id(), 403);
    }

    private function chatToApi(Chat $chat): array
    {
        return [
            'id' => $chat->id,
            'name' => $chat->displayName(),
            'phone' => $chat->phone,
            'avatar' => $chat->avatar(),
            'unread' => (int) $chat->unread_count,
            'in_crm' => (bool) $chat->contact_id,
            'unidentified' => LidResolver::isLid($chat->remote_jid),
            'channel' => $chat->instance ? $chat->instance->channelKey() : 'whatsapp',
            'last' => [
                'type' => $chat->last_message_type,
                'text' => $chat->last_message_preview,
                'from_me' => (bool) $chat->last_message_from_me,
                'status' => $chat->last_message_status,
                'at' => optional($chat->last_message_at)->toIso8601String(),
            ],
        ];
    }

    private function serialize(Chat $chat, $messages): array
    {
        $quotedIds = $messages->pluck('quoted_message_id')->filter()->unique()->values();
        $fromMe = $quotedIds->isEmpty()
            ? []
            : ChatMessage::where('chat_id', $chat->id)->whereIn('message_id', $quotedIds)->pluck('from_me', 'message_id')->map(fn ($v) => (bool) $v)->all();

        return $messages->map(fn (ChatMessage $m) => $m->toApi($fromMe))->values()->all();
    }

    private function previewOf(ChatMessage $message): string
    {
        if ($message->body) {
            return Str::limit(trim($message->body), 140, '…');
        }

        return match ($message->type) {
            'image' => '📷',
            'video' => '🎥',
            'audio' => '🎤',
            'document' => '📄 ' . $message->file_name,
            'sticker' => '🏷️',
            default => '',
        };
    }

    private function normalizeMime(?string $mime): string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return $mime !== '' ? $mime : 'application/octet-stream';
    }

    private function typeFromMime(string $mime, bool $asVoice): string
    {
        if ($asVoice || str_starts_with($mime, 'audio/')) {
            return 'audio';
        }
        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/') && $mime !== 'video/webm') {
            return 'video';
        }

        return 'document';
    }

    private function isInlineSafe(string $mime): bool
    {
        return in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true)
            || (str_starts_with($mime, 'video/') && $mime !== 'video/x-msvideo')
            || str_starts_with($mime, 'audio/');
    }

    private function extensionFor(string $mime): string
    {
        return [
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/3gpp' => '3gp',
            'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/webm' => 'webm',
            'application/pdf' => 'pdf',
        ][$mime] ?? 'bin';
    }
}
