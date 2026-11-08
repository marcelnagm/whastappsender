<?php

namespace App\Services\Webhooks;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Deal;
use App\Models\Proposal;

/** The JSON body of an event: small, stable and without internal ids that mean nothing outside. */
class Payloads
{
    public static function build(array $ctx): array
    {
        $out = [];
        if (isset($ctx['deal']) && $ctx['deal'] instanceof Deal) {
            $d = $ctx['deal']->loadMissing(['contact', 'stage']);
            $out['deal'] = ['id' => $d->id, 'title' => $d->title, 'value' => (float) $d->value, 'status' => $d->status, 'stage' => optional($d->stage)->name, 'origin' => $d->origin,
                'contact' => $d->contact ? ['id' => $d->contact->id, 'name' => $d->contact->name, 'phone' => $d->contact->contact] : null];
        }
        if (isset($ctx['contact']) && $ctx['contact'] instanceof \App\Models\Contact) {
            $k = $ctx['contact'];
            $out['contact'] = ['id' => $k->id, 'name' => $k->name, 'phone' => $k->contact, 'email' => $k->email, 'birthday' => optional($k->birthday)->toDateString()];
        }
        if (isset($ctx['chat']) && $ctx['chat'] instanceof Chat) {
            $c = $ctx['chat'];
            $out['chat'] = ['id' => $c->id, 'name' => $c->name, 'phone' => $c->phone, 'assigned_to' => $c->assigned_to];
        }
        if (isset($ctx['message']) && $ctx['message'] instanceof ChatMessage) {
            $out['message'] = ['id' => $ctx['message']->id, 'type' => $ctx['message']->type, 'text' => $ctx['message']->body];
        } elseif (isset($ctx['text'])) {
            $out['message'] = ['text' => (string) $ctx['text']];
        }
        if (isset($ctx['proposal']) && $ctx['proposal'] instanceof Proposal) {
            $out['proposal'] = ['id' => $ctx['proposal']->id, 'status' => $ctx['proposal']->status ?? null];
        }
        foreach (['tag_id', 'stage_id', 'score', 'scale'] as $k) {
            if (isset($ctx[$k])) {
                $out[$k] = $ctx[$k];
            }
        }

        return $out;
    }
}
