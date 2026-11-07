<?php

namespace App\Services\Contacts;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\ChatNote;
use App\Models\Contact;
use App\Models\CsatSurvey;
use App\Models\Deal;
use App\Models\Proposal;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Everything that happened with one person, newest first: conversations, notes, deals, proposals, tasks, consent, surveys. */
class ContactTimeline
{
    /** @return Collection<int,array{at:\Carbon\Carbon,kind:string,icon:string,text:string}> */
    public function for(Contact $contact, int $limit = 80): Collection
    {
        $uid = (int) $contact->user_id;
        $e = collect();
        $add = function ($at, string $kind, string $icon, string $text) use ($e) {
            if ($at) {
                $e->push(['at' => \Illuminate\Support\Carbon::parse($at), 'kind' => $kind, 'icon' => $icon, 'text' => $text]);
            }
        };

        $chatIds = Chat::where('user_id', $uid)->where('contact_id', $contact->id)->pluck('id');

        ChatMessage::whereIn('chat_id', $chatIds)->orderByDesc('id')->limit(40)->get()->each(function ($m) use ($add) {
            $body = $m->body ? mb_substr($m->body, 0, 120) : '[' . ($m->type ?: 'media') . ']';
            $add($m->message_timestamp ?? $m->created_at, 'message', $m->from_me ? 'bi-send' : 'bi-chat-left-text', ($m->from_me ? __('Sent') : __('Received')) . ': ' . $body);
        });
        ChatNote::whereIn('chat_id', $chatIds)->get()->each(fn ($n) => $add($n->created_at, 'note', 'bi-sticky', __('Internal note') . ': ' . mb_substr($n->body, 0, 140)));

        Deal::where('user_id', $uid)->where('contact_id', $contact->id)->get()->each(function ($d) use ($add) {
            $add($d->created_at, 'deal', 'bi-kanban', __('Deal created') . ': ' . $d->title);
            if (in_array($d->status, ['won', 'lost'], true) && $d->closed_at) {
                $add($d->closed_at, 'deal', $d->status === 'won' ? 'bi-trophy' : 'bi-x-circle', ($d->status === 'won' ? __('Deal won') : __('Deal lost')) . ': ' . $d->title);
            }
        });
        $dealIds = Deal::where('user_id', $uid)->where('contact_id', $contact->id)->pluck('id');
        Proposal::whereIn('deal_id', $dealIds)->get()->each(function ($p) use ($add) {
            $add($p->sent_at ?? $p->created_at, 'proposal', 'bi-file-earmark-text', __('Proposal') . ' #' . $p->number . ' (' . $p->status . ')');
        });

        Task::where('user_id', $uid)->where('contact_id', $contact->id)->get()->each(function ($t) use ($add) {
            $add($t->created_at, 'task', 'bi-check2-square', __('Task') . ': ' . $t->title);
            $add($t->done_at, 'task', 'bi-check2-all', __('Task done') . ': ' . $t->title);
        });

        DB::table('consent_logs')->where('user_id', $uid)->where('contact_id', $contact->id)->get()->each(
            fn ($c) => $add($c->created_at, 'consent', 'bi-shield-check', ($c->action === 'granted' ? __('Consent granted') : __('Consent revoked')) . ' (' . $c->source . ')')
        );
        CsatSurvey::whereIn('chat_id', $chatIds)->where('status', 'answered')->get()->each(fn ($s) => $add($s->answered_at, 'survey', 'bi-emoji-smile', __('Satisfaction grade') . ': ' . $s->score));
        $add($contact->created_at, 'contact', 'bi-person-plus', __('Contact created'));

        return $e->sortByDesc(fn ($x) => $x['at']->timestamp)->values()->take($limit);
    }
}
