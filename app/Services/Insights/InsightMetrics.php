<?php

namespace App\Services\Insights;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\DealItem;
use App\Models\KnowledgeQuestion;
use App\Models\User;
use App\Services\Contacts\Lifecycle;
use App\Services\Pipeline\Renewals;
use App\Services\Reports\WinLossReport;
use Illuminate\Support\Carbon;

/**
 * The numbers of the business for a period, computed by code from the account's own data. This is the ONLY source of
 * figures the AI may use: it interprets them, it never calculates or looks them up.
 */
class InsightMetrics
{
    public function collect(User $owner, int $days): array
    {
        $to = now()->endOfDay();
        $from = now()->subDays($days - 1)->startOfDay();
        $prevTo = $from->copy()->subSecond();
        $prevFrom = $from->copy()->subDays($days);

        $wl = app(WinLossReport::class);
        $cur = $wl->build($owner, $from, $to);
        $prev = $wl->build($owner, $prevFrom, $prevTo);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'customers' => [
                'total_contacts' => Contact::where('user_id', $owner->id)->count(),
                'new_contacts' => $this->newContacts($owner, $from, $to),
                'new_contacts_previous_period' => $this->newContacts($owner, $prevFrom, $prevTo),
                'by_lifecycle_stage' => app(Lifecycle::class)->counts($owner),
            ],
            'conversations' => [
                'active_conversations' => Chat::where('user_id', $owner->id)->whereBetween('last_message_at', [$from, $to])->count(),
                'messages_received' => $this->messages($owner, false, $from, $to),
                'messages_sent' => $this->messages($owner, true, $from, $to),
                'messages_received_previous_period' => $this->messages($owner, false, $prevFrom, $prevTo),
            ],
            'sales' => [
                'deals_created' => Deal::where('user_id', $owner->id)->whereBetween('created_at', [$from, $to])->count(),
                'open_deals' => Deal::where('user_id', $owner->id)->where('status', 'open')->count(),
                'open_pipeline_value' => round((float) Deal::where('user_id', $owner->id)->where('status', 'open')->sum('value'), 2),
                'won' => $cur['won'], 'lost' => $cur['lost'], 'win_rate_percent' => $cur['win_rate'],
                'won_value' => $cur['won_value'], 'lost_value' => $cur['lost_value'],
                'won_previous_period' => $prev['won'], 'won_value_previous_period' => $prev['won_value'], 'win_rate_percent_previous_period' => $prev['win_rate'],
                'average_won_deal' => $cur['won'] ? round($cur['won_value'] / $cur['won'], 2) : null,
                'lost_reasons' => array_slice($cur['reasons'], 0, 6),
                'by_origin' => array_slice($cur['by_origin'], 0, 8),
                'by_person' => array_slice($cur['by_owner'], 0, 8),
                'top_products' => $this->topProducts($owner, $from, $to),
            ],
            'subscriptions' => app(Renewals::class)->summary($owner) + ['renewing_next_30_days' => app(Renewals::class)->upcoming($owner, 30)->count()],
            'unanswered_customer_questions' => KnowledgeQuestion::where('user_id', $owner->id)->orderByDesc('hits')->limit(6)->get(['question', 'hits'])->map(fn ($q) => ['question' => $q->question, 'times' => $q->hits])->all(),
        ];
    }

    private function newContacts(User $owner, Carbon $from, Carbon $to): int
    {
        return Contact::where('user_id', $owner->id)->whereBetween('created_at', [$from, $to])->count();
    }

    private function messages(User $owner, bool $fromMe, Carbon $from, Carbon $to): int
    {
        return ChatMessage::where('user_id', $owner->id)->where('from_me', $fromMe)->whereBetween('created_at', [$from, $to])->count();
    }

    /** Best sellers among won deals that were built with items. */
    private function topProducts(User $owner, Carbon $from, Carbon $to): array
    {
        $ids = Deal::where('user_id', $owner->id)->where('status', 'won')->whereBetween('closed_at', [$from, $to])->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $rows = DealItem::whereIn('deal_id', $ids)->get()->groupBy('name')->map(fn ($g, $name) => [
            'product' => (string) $name, 'quantity' => round((float) $g->sum('quantity'), 2), 'revenue' => round((float) $g->sum(fn ($i) => $i->total()), 2),
        ])->sortByDesc('revenue')->values()->take(8)->all();

        return $rows;
    }
}
