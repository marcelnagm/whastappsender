<?php

namespace App\Services\AI;

use App\Jobs\SendAiReplyJob;
use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AiAutoReplyService
{
    public function handleInbound(string $instanceName, array $data, string $text): void
    {
        $this->debugFlow('Starting AI auto-reply evaluation', [
            'instance' => $instanceName,
            'remoteJid' => $data['key']['remoteJid'] ?? null,
            'messageId' => $data['key']['id'] ?? null,
        ]);

        // if (($data['key']['fromMe'] ?? false) === true) {
        //     $this->debugFlow('Aborted: fromMe message');
        //     return;
        // }

        $remoteJid = (string) ($data['key']['remoteJid'] ?? '');
        if ($this->isGroupMessage($remoteJid)) {
            $this->debugFlow('Aborted: group message', ['remoteJid' => $remoteJid]);
            return;
        }

        $text = trim($text);
        if ($text === '') {
            $this->debugFlow('Aborted: empty text');
            return;
        }

        $instance = Instance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            $this->debugFlow('Aborted: instance not found', ['instance' => $instanceName]);
            return;
        }

        $contact = $this->resolveContact($instance->user_id, $data);
        if (!$contact || (int) ($contact->ignore_me ?? 0) === 1) {
            $this->debugFlow('Aborted: contact missing or ignored', [
                'contact' => $contact->contact ?? null,
                'lid' => $contact->lid ?? ($data['key']['remoteJid'] ?? null),
                'ignore_me' => $contact->ignore_me ?? null,
            ]);
            return;
        }

        $user = User::find($instance->user_id);
        if (!$user || !$user->ai_enabled || $user->ai_mode !== 'auto') {
            $this->debugFlow('Aborted: AI disabled or not in auto mode', [
                'user_id' => $instance->user_id,
                'ai_enabled' => $user->ai_enabled ?? null,
                'ai_mode' => $user->ai_mode ?? null,
            ]);
            return;
        }

        $session = AiSession::firstOrCreate(
            [
                'user_id' => $user->id,
                'contact_id' => $contact->id,
                'instance_id' => $instance->id,
            ],
            [
                'status' => 'active',
                'human_handoff' => false,
            ]
        );

        if ($session->human_handoff || $session->status !== 'active') {
            $this->debugFlow('Aborted: session in handoff or inactive', [
                'session_id' => $session->id,
                'status' => $session->status,
                'human_handoff' => $session->human_handoff,
            ]);
            return;
        }

        $history = $session->messages()
            ->orderBy('id', 'desc')
            ->limit(20)
            ->get()
            ->reverse()
            ->map(function (AiMessage $message) {
                return [
                    'role' => $message->role,
                    'content' => $message->content,
                ];
            })
            ->values()
            ->all();

        $result = app(GroqAgentResponder::class)->generateReply($user, $history);
        $reply = trim((string) ($result['reply'] ?? ''));
        if ($reply === '') {
            $this->debugFlow('Aborted: GROQ returned empty reply', [
                'session_id' => $session->id,
            ]);
            return;
        }

        $outbound = AiMessage::create([
            'ai_session_id' => $session->id,
            'direction' => 'outbound',
            'role' => 'assistant',
            'content' => $reply,
            'provider' => 'groq',
            'model' => $result['model'] ?? null,
            'tokens_in' => $result['tokens_in'] ?? null,
            'tokens_out' => $result['tokens_out'] ?? null,
            'status' => 'queued',
            'raw_payload' => $result['raw'] ?? null,
        ]);

        SendAiReplyJob::dispatch($outbound->id, $instance->id, $contact->id)->onQueue('disparos');
        $this->debugFlow('AI reply queued successfully', [
            'session_id' => $session->id,
            'ai_message_id' => $outbound->id,
            'instance_id' => $instance->id,
            'contact_id' => $contact->id,
        ]);
    }

    private function resolveContactNumber(array $data): ?string
    {
        $remoteJid = $data['key']['remoteJid'] ?? '';
        $senderPn = $data['key']['senderPn'] ?? null;

        if (str_contains($remoteJid, '@lid') && $senderPn) {
            return explode('@', $senderPn)[0];
        }

        if (!$remoteJid) {
            return null;
        }

        return explode('@', $remoteJid)[0];
    }

    private function resolveContact(int $userId, array $data): ?Contact
    {
        $remoteJid = (string) ($data['key']['remoteJid'] ?? '');
        $senderPn = $data['key']['senderPn'] ?? null;
        $isLid = str_contains($remoteJid, '@lid');

        if ($isLid) {
            if ($senderPn) {
                $cleanNumber = explode('@', $senderPn)[0];
                $contact = Contact::where('user_id', $userId)
                    ->where('contact', $cleanNumber)
                    ->first();

                if ($contact && $contact->lid !== $remoteJid) {
                    $contact->update(['lid' => $remoteJid]);
                    $this->debugFlow('LID synced with contact', [
                        'contact_id' => $contact->id,
                        'contact' => $contact->contact,
                        'lid' => $remoteJid,
                    ]);
                }

                return $contact;
            }

            $contactByLid = Contact::where('user_id', $userId)
                ->where('lid', $remoteJid)
                ->first();

            if ($contactByLid) {
                $this->debugFlow('Contact resolved by LID', [
                    'contact_id' => $contactByLid->id,
                    'lid' => $remoteJid,
                ]);
            }

            return $contactByLid;
        }

        $contactNumber = $this->resolveContactNumber($data);
        if (!$contactNumber) {
            $this->debugFlow('Aborted: contact number could not be resolved');
            return null;
        }

        return Contact::where('user_id', $userId)
            ->where('contact', $contactNumber)
            ->first();
    }

    private function isGroupMessage(string $remoteJid): bool
    {
        return str_contains($remoteJid, '@g.us');
    }

    private function debugFlow(string $message, array $context = []): void
    {
        if (!config('services.ai.debug_flow', false)) {
            return;
        }

        Log::info('[AI_FLOW_DEBUG] ' . $message, $context);
    }
}
