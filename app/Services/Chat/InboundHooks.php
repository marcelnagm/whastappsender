<?php

namespace App\Services\Chat;

use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Pipeline\LeadDeals;
use Illuminate\Support\Facades\Log;

/** What a message from a customer sets in motion: the conversation is distributed to the team and a deal may be opened. */
class InboundHooks
{
    public static function onInbound(ChatMessage $m): void
    {
        try {
            // imported history is not new activity
            $at = $m->message_timestamp ?? $m->created_at ?? now();
            if ($at->lt(now()->subMinutes(30))) {
                return;
            }
            $chat = $m->chat;
            $owner = User::find($m->user_id);
            if (!$chat || !$owner) {
                return;
            }
            app(\App\Services\Webhooks\OutboundWebhooks::class)->emit('message.received', $owner->id, ['chat' => $chat, 'message' => $m]);
            app(Sequences::class)->stopOnReply($chat);
            if (app(Csat::class)->capture($chat, $m)) {
                return;
            }
            app(Snooze::class)->wake($chat);   // the customer wrote: the conversation is back in the inbox
            if ($chat->closed_at) {
                app(Csat::class)->reopen($chat);
                $chat = $chat->fresh();
            }
            // a conversation answered by the Typebot flow is neither greeted nor distributed until a person is called in
            if (!app(\App\Services\Bots\TypebotBot::class)->onInbound($chat, $owner, $m)) {
                app(ChatAssignment::class)->autoAssign($chat, $owner);
                app(AutoMessages::class)->onInbound($chat, $owner, $m);
            }
            if ($m->body && \App\Services\Chat\HumanRequests::detect((string) $m->body)) {
                app(\App\Services\Chat\HumanRequests::class)->request($chat->fresh(), __('The customer asked for a person.'));
            }
            $deals = app(LeadDeals::class);
            $deals->fromInbound($chat->fresh(), $owner, $m);
            if ($m->body) {
                $chat = $chat->fresh();
                $ctx = ['chat' => $chat, 'text' => (string) $m->body];
                if ($open = $deals->openDealFor($chat)) {
                    $ctx['deal'] = $open;
                }
                app(\App\Services\Automation\Automations::class)->fire('message.keyword', $owner, $ctx);
            }
        } catch (\Throwable $e) {
            Log::warning('Inbound hooks failed: ' . $e->getMessage(), ['chat_message_id' => $m->id]);
        }
    }
}
