<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Models\CampaignItem;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GerarJobsWhatsApp extends Command
{
    protected $signature = 'whatsapp:gerar {item_id}';
    protected $description = 'Gera a fila de disparos otimizada';

    public function handle()
    {
        $id = $this->argument('item_id');
        $campaignItem = CampaignItem::with('campaign')->find($id);

        if (!$campaignItem) {
            $this->error("Item da campanha não encontrado!");
            return self::FAILURE;
        }

        // 1. Chunking para não estourar a RAM
        // Buscamos apenas contatos válidos (já minerados/validados)
        Contact::where('user_id', $campaignItem->user_id)
            ->whereNull('ignore_me') // Regra: só gera para quem foi minerado          
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
                        // O payload agora é gerado e guardado como JSON puro
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ];
                }

                // 2. Insert de alta performance (1 query por 1000 registros)
                WhatsappJob::insert($jobs);

                $this->info("Lote de 1000 jobs inserido...");
            });

        $this->info("Sucesso! Fila de disparos gerada com performance.");
        return self::SUCCESS;
    }
}
