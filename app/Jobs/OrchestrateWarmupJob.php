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

        // Ask the model for ~40 warmup lines
        $script = $generator->generate(40, 'day-to-day and technology');
        $delayAcumulado = 0;

        foreach ($script as $index => $frase) {
            // Even index: admin instance -> target. Odd: target -> admin.
            $senderName = ($index % 2 === 0) ? $admin->instance_name : $target->instance_name;
            $receiverPhone = ($index % 2 === 0) ? $target->instance_name  : $admin->instance_name ; // requires phone column

            // Controlled randomness: 1–4 minutes between lines
            $delayAcumulado += rand(60, 240);

            // Dedicated warmup queue
            SendWarmupMessageJob::dispatch($senderName, $receiverPhone, $frase)
                ->delay(now()->addSeconds($delayAcumulado))
                ->onQueue('warmup');
        }
    }
}
