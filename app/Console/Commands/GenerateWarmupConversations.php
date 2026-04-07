<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Instance;
use App\Jobs\OrchestrateWarmupJob;
use Illuminate\Support\Facades\Log;

class GenerateWarmupConversations extends Command
{
    protected $signature = 'warmup:generate';
    protected $description = 'Gera rotinas de aquecimento para instâncias ativas';

    public function handle()
    {
        // 1. Busca instâncias Admin (Origem)
        $admins = Instance::join('users', 'instances.user_id', '=', 'users.id')
            ->where('users.role', 'admin')
            ->where('instances.status', 'connected')
            ->select('instances.*') // Garante que retornamos objetos do tipo Instance
            ->get();
        // 2. Busca instâncias em Warmup (Alvos)
        
        $targets = Instance::where('warmup', 1)
            ->where('status', 'connected')
            ->whereHas('user', function ($query) {
                $query->where('role', '!=', 'admin');
            })
            ->get();

        if ($admins->isEmpty() || $targets->isEmpty()) {
            $this->error("Faltam instâncias Admin ou Alvos em modo Warmup.");
            return;
        }

        $this->info("Iniciando orquestração para " . $targets->count() . " chips.");

        foreach ($targets as $target) {
            // Seleciona um admin aleatório para interagir com este chip
            $admin = $admins->random();

            // Dispara o Orquestrador que gera as 40 frases e agenda os delays
            OrchestrateWarmupJob::dispatch($admin->id, $target->id);

            $this->line("Agendado: Admin [{$admin->instance_name}] <-> Target [{$target->instance_name}]");
        }

        Log::info("Warmup: " . $targets->count() . " conversas orquestradas.");
    }
}
