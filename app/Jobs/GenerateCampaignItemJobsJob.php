<?php

namespace App\Jobs;

use App\Models\CampaignItem;
use App\Models\User;
use App\Notifications\CampaignItemGenerationStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class GenerateCampaignItemJobsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $itemId;

    public function __construct(int $itemId)
    {
        $this->itemId = $itemId;
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $item = CampaignItem::select('id', 'name', 'user_id')->find($this->itemId);
        if (!$item) {
            return;
        }

        $user = User::find($item->user_id);

        try {
            Artisan::call('whatsapp:gerar', [
                'item_id' => $this->itemId,
            ]);

            if ($user) {
                $user->notify(new CampaignItemGenerationStatus(
                    (int) $item->id,
                    (string) $item->name,
                    'completed'
                ));
            }
        } catch (\Throwable $e) {
            Log::error('Failed to generate campaign jobs', [
                'campaign_item_id' => $this->itemId,
                'error' => $e->getMessage(),
            ]);

            if ($user) {
                $user->notify(new CampaignItemGenerationStatus(
                    (int) $item->id,
                    (string) $item->name,
                    'error',
                    'Verifique os logs do worker para mais detalhes.'
                ));
            }

            throw $e;
        }
    }
}
