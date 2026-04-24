<?php

namespace App\Services\AI;

use App\Models\AiMessage;
use App\Models\AiSession;
use App\Models\Contact;
use App\Models\Instance;
use App\Models\User;

class AiInboundMessageRecorder
{
    public function record(string $instanceName, array $data, string $text): void
    {
        if (($data['key']['fromMe'] ?? false) === true) {
            return;
        }

        $remoteJid = $data['key']['remoteJid'] ?? '';
        if ($this->isGroupMessage($remoteJid)) {
            return;
        }

        if (trim($text) === '') {
            return;
        }

        $instance = Instance::where('instance_name', $instanceName)->first();
        if (!$instance) {
            return;
        }

        $contact = $this->resolveContact($instance->user_id, $data);

        if (!$contact) {
            return;
        }

        $user = User::find($instance->user_id);
        if (!$user || !$user->ai_enabled) {
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

        AiMessage::create([
            'ai_session_id' => $session->id,
            'direction' => 'inbound',
            'role' => 'user',
            'channel_message_id' => $data['key']['id'] ?? null,
            'content' => $text,
            'provider' => 'evolution',
            'model' => null,
            'status' => 'ok',
            'raw_payload' => $data,
        ]);

        $session->update(['last_inbound_at' => now()]);
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
                }

                return $contact;
            }

            return Contact::where('user_id', $userId)
                ->where('lid', $remoteJid)
                ->first();
        }

        $contactNumber = $this->resolveContactNumber($data);
        if (!$contactNumber) {
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
}
