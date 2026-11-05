<?php

namespace App\Jobs;

use App\Models\CampaignItem;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Campaigns\CampaignAudience;
use App\Services\Channels\ReplyWindow;
use App\Services\Chat\ChatMessageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sends a campaign message to the Telegram / Instagram / Facebook / TikTok contacts of its audience. These channels cannot
 * start conversations, so each recipient is answered in the conversation they opened, only while the reply window is
 * open. Sends go through the normal chat job (same account, same limits), spaced out, and count against the monthly quota.
 */
class DispatchCampaignChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public int $itemId)
    {
    }

    public function handle(CampaignAudience $audiences, Entitlements $entitlements, ChatMessageRecorder $recorder): void
    {
        if (Cache::has('system_panic_mode')) {
            return;
        }

        $item = CampaignItem::find($this->itemId);
        $owner = $item ? User::find($item->user_id) : null;
        if (!$item || !$owner || !$item->audience) {
            return;
        }

        $text = trim((string) $item->text);
        if ($text === '') {
            return;
        }

        $step = max(0, (int) config('campaigns.channel_delay_seconds', 3));
        $sent = 0;
        $skipped = 0;

        foreach (array_diff(CampaignAudience::normalize($item->audience)['channels'], ['whatsapp']) as $channel) {
            $audiences->query((int) $item->user_id, $item->audience, $channel)->chunkById(200, function ($contacts) use ($channel, $item, $owner, $entitlements, $recorder, $step, &$sent, &$skipped) {
                foreach ($contacts as $contact) {
                    if (!$entitlements->allows($owner, 'messages_per_month')) {
                        $skipped++;
                        continue;
                    }

                    $chat = Chat::with('instance')->where('user_id', $owner->id)->where('contact_id', $contact->id)
                        ->whereHas('instance', fn ($q) => $q->where('channel', $channel)->where('status', 'connected'))
                        ->orderByDesc('last_message_at')->first();

                    if (!$chat || !ReplyWindow::isOpen($chat)) {
                        $skipped++;
                        continue;
                    }

                    $message = ChatMessage::create([
                        'chat_id' => $chat->id, 'instance_id' => $chat->instance_id, 'user_id' => $owner->id,
                        'from_me' => true, 'source' => 'campaign', 'type' => 'text', 'body' => $item->text, 'status' => 'pending',
                        'message_timestamp' => now(),
                    ]);
                    $recorder->touchChat($chat, $message, true);
                    $entitlements->consume($owner, 'messages');

                    SendChatMessageJob::dispatch($message->id)->onQueue('default')->delay(now()->addSeconds($sent * $step));
                    $sent++;
                }
            });
        }

        Log::info("Campaign item {$item->id}: {$sent} message(s) queued on other channels, {$skipped} skipped.");
    }
}
