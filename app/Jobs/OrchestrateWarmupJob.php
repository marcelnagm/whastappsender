<?php

namespace App\Jobs;

use App\Models\Instance;
use App\Services\Contracts\ConversationGeneratorInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class OrchestrateWarmupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $adminId;
    protected $targetId;

    public function __construct($adminId, $targetId)
    {
        $this->adminId = $adminId;
        $this->targetId = $targetId;
    }

    public function handle(ConversationGeneratorInterface $generator)
    {
        $admin = Instance::find($this->adminId);
        $target = Instance::find($this->targetId);

        if (!$admin || !$target) return;

        // Pede para a IA gerar as 40 frases
        $script = $generator->generate(40, 'dia a dia e tecnologia');
        $delayAcumulado = 0;

        foreach ($script as $index => $frase) {
            // Regra: Índice PAR = Admin enviando pro Target. ÍMPAR = Target respondendo pro Admin.
            $senderName = ($index % 2 === 0) ? $admin->instance_name : $target->instance_name;
            $receiverPhone = ($index % 2 === 0) ? $target->instance_name  : $admin->instance_name ; // Requer coluna 'phone'

            // Caos Controlado: Intervalo entre 1 e 4 minutos por mensagem
            $delayAcumulado += rand(60, 240);

            // Dispara para a fila dedicada
            SendWarmupMessageJob::dispatch($senderName, $receiverPhone, $frase)
                ->delay(now()->addSeconds($delayAcumulado))
                ->onQueue('warmup');
        }
    }
}
