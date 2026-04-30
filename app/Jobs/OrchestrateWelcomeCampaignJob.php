<?php

namespace App\Jobs;

use App\Models\CampaignItem;
use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrchestrateWelcomeCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $campaignItemId;

    public function __construct(int $campaignItemId)
    {
        $this->campaignItemId = $campaignItemId;
    }

    public function handle(): void
    {
        $campaignItem = CampaignItem::find($this->campaignItemId);
        if (!$campaignItem) {
            return;
        }

        $contacts = Contact::where('user_id', $campaignItem->user_id)
            ->whereNull('ignore_me')
            ->where('status', 'ativo')
            ->select('id')
            ->get();

        $delaySeconds = 0;

        foreach ($contacts as $contact) {
            $delaySeconds += rand(4, 12);

            SendWelcomeCampaignMessageJob::dispatch(
                (int) $campaignItem->id,
                (int) $contact->id,
                (int) $campaignItem->user_id
            )->delay(now()->addSeconds($delaySeconds))
                ->onQueue('default');
        }
    }
}
