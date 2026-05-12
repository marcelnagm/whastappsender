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
                ->hourly()
                ->withoutOverlapping();

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
