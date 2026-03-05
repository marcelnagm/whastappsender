<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Contact;
use App\Models\CampaignItem;
use App\Models\WhatsappJob;
use Illuminate\Support\Facades\DB;

class GerarJobsWhatsApp extends Command
{
    protected $signature = 'whatsapp:gerar {item_id?}';
    protected $description = 'Gera a fila de disparos com URL completa no endpoint';

    public function handle()
    {
        // 1. Captura do ID
        $id = $this->argument('item_id') ?? $this->ask("Qual id do item?");
        $campaignItem = CampaignItem::find($id);

        if (!$campaignItem) {
            $this->error("Item não encontrado!");
            return Command::FAILURE;
        }

        $this->info("Você escolheu este id = " . $id);
        $this->info("Texto: " . $campaignItem->text);
        $this->info("Imagem: " . $campaignItem->image);

        // 2. Confirmação (Uso do confirm nativo para evitar erros de tipagem)
        if (!$this->confirm("Você confirma a geração dos jobs?", true)) {
            $this->warn("Operação cancelada.");
            return Command::SUCCESS;
        }

        // 3. Busca de contatos
        $contatos = Contact::where('user_id', $campaignItem->user_id)
            ->whereNotNull('contact')
            ->pluck('contact');

        if ($contatos->isEmpty()) {
            $this->error("Nenhum contato encontrado para este usuário.");
            return Command::FAILURE;
        }

        $this->info("Gerando " . $contatos->count() . " registros...");

        // 4. Loop de Inserção
        DB::transaction(function () use ($contatos, $campaignItem) {
            foreach ($contatos as $c) {
                $job = new WhatsappJob();

                // Mantendo sua estrutura de URL completa por sua conta e risco
                $job->endpoint = env('WHATSAPP_PROTOCOL', 'http') . '://' .
                    env('WHATSAPP_URL', 'localhost') . ':' .
                    env('WHATSAPP_PORT', '8080') .
                    $campaignItem->getOperation();

                // O Model WhatsappJob PRECISA ter protected $casts = ['payload' => 'array']
                $job->payload = $campaignItem->generate($c);
                $job->campaign_id = $campaignItem->campaign_id;
                $job->campaign_item_id = $campaignItem->id;
                $job->user_id = $campaignItem->user_id;
                $job->status  = 'pendente';
                $job->save();
            }
        });

        $this->info("Sucesso! Tudo pronto para o disparo.");
        return Command::SUCCESS;
    }
}
