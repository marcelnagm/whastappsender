<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class PanicController extends Controller
{
    //
    public function index()
    {

        return view('panic.index');
    }

    public function toggle()
    {
        $panicKey = 'system_panic_mode';

        if (Cache::has($panicKey)) {
            Cache::forget($panicKey);
            $msg = 'Sending system resumed successfully.';
        } else {
            // Set flag with no TTL
            Cache::forever($panicKey, true);
            $msg = 'SYSTEM PAUSED: Workers will skip new processing.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Physically clear the queue on the driver (Redis / database).
     */
    public function clear()
    {
        try {
            Artisan::call('queue:clear', [
                '--queue' => 'disparos',
                '--force' => true,      // Skip confirmation prompt
                '--no-interaction' => true // Avoid Symfony waiting on STDIN
            ]);

            return back()->with('success', 'Send queue cleared. All pending jobs were removed.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to clear queue: ' . $e->getMessage());
        }
    }

    public function warmup()
    {
        try {
            Artisan::call('queue:clear', [
                '--queue' => 'warmup',
                '--force' => true,
                '--no-interaction' => true
            ]);
            return back()->with('success', 'Warmup queue cleared. All pending jobs were removed.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to clear queue: ' . $e->getMessage());
        }
    }
}
