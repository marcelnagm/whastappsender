<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Instance;
use App\Jobs\OrchestrateWarmupJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateWarmupConversations extends Command
{
    protected $signature = 'warmup:generate';
    protected $description = 'Generate warmup routines for active instances';

    public function handle()
    {
        if (Cache::has('system_panic_mode')) {
            
            return 1;
        }
        // 1. Admin instances (source)
        $admins = Instance::join('users', 'instances.user_id', '=', 'users.id')
            ->where('users.role', 'admin')
            ->where('instances.status', 'connected')
            ->where('instances.channel', 'whatsapp')
            ->select('instances.*') // Ensure hydrated models are Instance
            ->get();
        // 2. Instances in warmup (targets)

        $targets = Instance::where('warmup', 1)
            ->where('channel', 'whatsapp')
            ->where('status', 'connected')
            ->whereHas('user', function ($query) {
                $query->where('role', '!=', 'admin');
            })
            ->with('user')
            ->get()
            // Plan: warmup is a paid feature (blocked accounts included)
            ->filter(fn ($instance) => app(\App\Services\Billing\Entitlements::class)->can($instance->user, 'warmup'))
            ->values();

        if ($admins->isEmpty() || $targets->isEmpty()) {
            $this->error("Missing admin instances or targets in warmup mode.");
            return;
        }

        $this->info("Starting orchestration for " . $targets->count() . " chips.");

        foreach ($targets as $target) {
            // Pick a random admin instance to ping this SIM
            $admin = $admins->random();

            // Orchestrator builds ~40 lines and staggers delays
            OrchestrateWarmupJob::dispatch($admin->id, $target->id);

            $this->line("Scheduled: Admin [{$admin->instance_name}] <-> Target [{$target->instance_name}]");
        }

        Log::info("Warmup: " . $targets->count() . " conversation(s) orchestrated.");
    }
}
