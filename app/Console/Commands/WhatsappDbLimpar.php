<?php

namespace App\Console\Commands;

use Exception;
use GrahamCampbell\ResultType\Success;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class WhatsappDbLimpar extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:db:limpar';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpa os concluidos';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {

        try {

            $query = \App\Models\WhatsappJob::where('status', 'erro');



            // Opcional: Mover para tabela de histórico antes de deletar
            // DB::insert("insert into whatsapp_jobs_history select * from whatsapp_jobs where...");

            $query->delete();

            return 0;
        } catch (Exception $ex) {
            Log::error($ex->getMessage());
            return 1;
        }
    }
}
