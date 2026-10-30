<?php

namespace App\Services\Channels;

use App\Models\Contact;
use App\Models\Instance;
use App\Services\AI\AiAutoReplyService;
use App\Services\AI\AiInboundMessageRecorder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Hands a message received on a non-WhatsApp channel to the same AI flow WhatsApp uses (reply leaves through the same instance). */
class InboundAiBridge
{
    /**
     * @param string $jid "tg:<id>" / "ig:<id>" (the contact column has the same value)
     */
    public static function run(Instance $instance, Contact $contact, string $jid, string $messageId, ?string $text, string $type): void
    {
        $text = trim((string) $text);

        // opt-out, same idea as #sair on WhatsApp
        if (in_array(Str::lower($text), ['/stop', '#sair'], true)) {
            $contact->update(['ignore_me' => 1]);

            return;
        }
        if ($text === '' || (int) $contact->ignore_me === 1) {
            return; // media without caption: nothing for the AI to answer
        }

        $data = ['key' => ['remoteJid' => $jid, 'id' => $messageId, 'fromMe' => false], 'message' => ['conversation' => $text]];

        try {
            app(AiInboundMessageRecorder::class)->record($instance->instance_name, $data, $text);
            app(AiAutoReplyService::class)->handleInbound($instance->instance_name, $data, $text);
        } catch (\Throwable $e) {
            Log::warning('AI flow failed on ' . $instance->channelKey() . ': ' . $e->getMessage(), ['instance_id' => $instance->id]);
        }
    }
}
