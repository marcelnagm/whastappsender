<?php

namespace App\Console\Commands;

use App\Models\WhatsappJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class envioQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:queue';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gera Queue de disparo';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (Cache::has('system_panic_mode')) {
            
            return 1;
        }

        $jobs = WhatsappJob::whereIn('status', ['pendente', 'erro'])
            ->where('tentativas', '<', 3)
            ->orderBy('id', 'asc')
            ->limit(100)
            ->get();

        if ($jobs->isEmpty()) {
            $this->warn("Nenhum job pendente encontrado no banco."); // Isso vai te avisar no terminal
            return;
        }

        $this->info("Encontrados " . $jobs->count() . " jobs. Despachando...");

        foreach ($jobs as $job) {
            $job->update(['status' => 'fila']);
            \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('disparos');
        }
        return Command::SUCCESS;
    }
}
