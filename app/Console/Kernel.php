<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')->hourly();

        if (env('WHATSAPP_AUTOSEND'))
            $schedule->command('whatsapp:queue')
                ->cron(config('whatsapp_queue.cron', '0 * * * *'))
                ->withoutOverlapping()
                ->onOneServer();

        // Is a session that should be sending still connected? Tells owners when sending stopped, admins when Evolution is down
        $schedule->command('instances:health')
            ->cron(config('instance_health.check_cron', '*/5 * * * *'))
            ->withoutOverlapping()
            ->onOneServer();

        // Fires QueueBusy (-> admin alert) when a queue is piling up. Only meaningful for the redis driver.
        if (config('queue.default') === 'redis') {
            $queues = collect(['disparos', 'default', 'warmup'])->map(fn ($q) => 'redis:' . $q)->implode(',');
            $schedule->command('queue:monitor', [$queues, '--max=' . (int) config('instance_health.queue_max_pending', 500)])
                ->everyFiveMinutes()
                ->onOneServer();
        }

        // Renewal invoices, grace period, blocking for non-payment (cheap: only touches live subscriptions)
        $schedule->command('billing:run')->hourly()->withoutOverlapping()->onOneServer();

        $schedule->command('warmup:generate')
            ->dailyAt('08:00')
            ->timezone('America/Boa_Vista') // Use local timezone for the schedule
            ->withoutOverlapping();
        // DB housekeeping: move or purge old records at 03:00 (optional)
        // $schedule->command('whatsapp:db:limpar')->dailyAt('03:00');
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
