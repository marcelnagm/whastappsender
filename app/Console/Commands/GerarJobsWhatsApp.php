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
            ->whereNull('ignore_me') // Regra: só gera para quem foi minerado          
            ->where('status','ativo') // Regra: só gera para quem foi minerado          
            ->chunkById(1000, function ($contatos) use ($campaignItem) {

                $jobs = [];
                $now = now();

                foreach ($contatos as $contato) {
                    $jobs[] = [
                        'user_id'          => $campaignItem->user_id,
                        'campaign_id'      => $campaignItem->campaign_id,
                        'campaign_item_id' => $campaignItem->id,
                        'contact_id'       => $contato->id, // A âncora que definimos
                        'status'           => 'pendente',
                        'endpoint'  => 
                            $campaignItem->getOperation(),
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ];
                }

                // 2. Insert de alta performance (1 query por 1000 registros)
                if (!empty($jobs)) {
                    WhatsappJob::insert($jobs);
                    $this->info("Job batch inserted...");
                }
            });

        $this->info("Success: send queue generated.");
        return self::SUCCESS;
    }
}
