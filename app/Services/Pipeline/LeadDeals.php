<?php

namespace App\Services\Pipeline;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\User;

/** Opens deals in the sales funnel from conversations and contact forms, without duplicating a deal that is still open. */
class LeadDeals
{
    public function __construct(private SalesPipeline $funnel)
    {
    }

    /** A person who is still in the funnel (deal open) for this conversation or contact. */
    public function openDealFor(Chat $chat): ?Deal
    {
        return Deal::where('user_id', $chat->user_id)->where('status', 'open')
            ->where(function ($q) use ($chat) {
                $q->where('chat_id', $chat->id);
                if ($chat->contact_id) {
                    $q->orWhere('contact_id', $chat->contact_id);
                }
            })->orderByDesc('id')->first();
    }

    /** New lead writing: first message ever, or back after a long silence. */
    public function fromInbound(Chat $chat, User $owner, ChatMessage $message): ?Deal
    {
        if (!$owner->deal_auto_chat || $this->openDealFor($chat)) {
            return null;
        }

        $days = max(1, (int) config('deals.new_after_days', 30));
        $previous = ChatMessage::where('chat_id', $chat->id)->where('from_me', false)->where('id', '<', $message->id)->max('created_at');
        if ($previous && \Illuminate\Support\Carbon::parse($previous)->gt(now()->subDays($days))) {
            return null; // an ongoing conversation, not a new lead
        }

        return $this->create($chat, $owner, 'chat');
    }

    /** A form arrived. $chat is where it landed (null when it was only stored). */
    public function fromForm(User $owner, ?Contact $contact, ?Chat $chat, string $title): ?Deal
    {
        if (!$owner->deal_auto_form || (!$contact && !$chat)) {
            return null;
        }
        $probe = $chat ?? new Chat(['user_id' => $owner->id, 'contact_id' => $contact->id]);
        if ($this->openDealFor($probe)) {
            return null;
        }

        return $this->create($probe, $owner, 'form', $title ?: null);
    }

    /** Button in the chat: always allowed, but one open deal per person. */
    public function manual(Chat $chat, User $owner, ?Pipeline $pipeline = null): ?Deal
    {
        return $this->openDealFor($chat) ? null : $this->create($chat, $owner, 'chat', null, $pipeline);
    }

    private function create(Chat $chat, User $owner, string $source, ?string $title = null, ?Pipeline $pipeline = null): Deal
    {
        $chat->loadMissing('contact');
        $pipeline ??= $this->funnel->defaultFor($owner);

        return $this->funnel->open($pipeline, [
            'title' => mb_substr($title ?: $chat->displayName(), 0, 160),
            'contact_id' => $chat->contact_id,
            'chat_id' => $chat->exists ? $chat->id : null,
            'assigned_to' => $chat->assigned_to,
            'source' => $source,
        ]);
    }
}
