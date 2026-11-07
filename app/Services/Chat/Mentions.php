<?php

namespace App\Services\Chat;

use App\Models\Chat;
use App\Models\ChatNote;
use App\Models\User;
use App\Notifications\MentionNotice;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** "@maria" in an internal note notifies Maria; "@maria-silva" when two people share a first name. */
class Mentions
{
    /** @return array<string,int> handle => user id */
    public function handles(User $owner): array
    {
        $people = app(ChatAssignment::class)->people($owner);
        $first = $people->groupBy(fn (User $u) => Str::slug(explode(' ', trim($u->name))[0]));
        $map = [];
        foreach ($people as $u) {
            $map[Str::slug($u->name)] = $u->id;
        }
        foreach ($first as $slug => $group) {
            if ($slug !== '' && $group->count() === 1) {
                $map[$slug] = $group->first()->id;
            }
        }

        return $map;
    }

    /** Who a text mentions (author excluded), as users. @return Collection<int,User> */
    public function mentioned(User $owner, string $text, ?int $authorId = null): Collection
    {
        if (!preg_match_all('/(?<![\p{L}\d])@([\p{L}\d][\p{L}\d._-]*)/u', $text, $m)) {
            return collect();
        }
        $handles = $this->handles($owner);
        $ids = collect($m[1])->map(fn ($t) => $handles[Str::slug(rtrim($t, '._-'))] ?? null)->filter()->unique()->reject(fn ($id) => $id === $authorId)->values();

        return $ids->isEmpty() ? collect() : User::whereIn('id', $ids)->get();
    }

    public function notify(ChatNote $note, Chat $chat, User $owner): int
    {
        $author = User::find($note->author_id);
        $who = $this->mentioned($owner, $note->body, $note->author_id);
        $label = $chat->name ?: ($chat->phone ?: __('Conversation'));
        foreach ($who as $u) {
            $u->notify(new MentionNotice(__(':name mentioned you in :chat: :text', ['name' => $author?->name ?: __('Team'), 'chat' => $label, 'text' => mb_substr($note->body, 0, 120)]), route('chat.index', ['chat' => $chat->id])));
        }

        return $who->count();
    }
}
