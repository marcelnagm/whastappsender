<?php

namespace App\Console\Commands;

use App\Jobs\OrchestrateWelcomeCampaignJob;
use Illuminate\Console\Command;
use App\Models\Contact;
use App\Models\CampaignItem;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Cache;

class GerarJobsWhatsApp extends Command
{
    protected $signature = 'whatsapp:gerar {item_id}';
    protected $description = 'Build optimized send queue';

    public function handle()
    {
        if (Cache::has('system_panic_mode')) {
            
            return 1;
        }

        $id = $this->argument('item_id');
        $campaignItem = CampaignItem::with('campaign')->find($id);

        if (!$campaignItem) {
            $this->error("Campaign item not found!");
            return self::FAILURE;
        }

        if ((bool) ($campaignItem->welcome_enabled ?? false)) {
            OrchestrateWelcomeCampaignJob::dispatch((int) $campaignItem->id)->onQueue('default');
            $this->info("Welcome flow orchestrated in the background.");
            return self::SUCCESS;
        }

        // 1. Chunk to avoid RAM spikes
        // Only valid (mined/validated) contacts
        Contact::where('user_id', $campaignItem->user_id)
            ->whereNull('ignore_me') // Only mined / validated contacts
            ->where('status','ativo') // Only active WhatsApp numbers
            ->chunkById(1000, function ($contacts) use ($campaignItem) {

                $jobs = [];
                $now = now();

                foreach ($contacts as $contact) {
                    $jobs[] = [
                        'user_id'          => $campaignItem->user_id,
                        'campaign_id'      => $campaignItem->campaign_id,
                        'campaign_item_id' => $campaignItem->id,
                        'contact_id'       => $contact->id, // Stable foreign key
                        'status'           => 'pendente',
                        'endpoint'  => 
                            $campaignItem->getOperation(),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ];
                }

                // 2. Bulk insert (one query per chunk of up to 1000 rows)
                if (!empty($jobs)) {
                    WhatsappJob::insert($jobs);
                    $this->info("Job batch inserted...");
                }
            });

        $this->info("Success: send queue generated.");
        return self::SUCCESS;
    }
}
