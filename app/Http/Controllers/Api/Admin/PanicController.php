<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class PanicController extends ApiController
{
    public function status()
    {
        return $this->success([
            'panic_mode' => Cache::has('system_panic_mode'),
        ]);
    }

    public function toggle()
    {
        $panicKey = 'system_panic_mode';

        if (Cache::has($panicKey)) {
            Cache::forget($panicKey);
            $msg = 'Sending system resumed.';
            $active = false;
        } else {
            Cache::forever($panicKey, true);
            $msg = 'SYSTEM PAUSED: Workers will skip new processing.';
            $active = true;
        }

        return $this->success(['panic_mode' => $active], $msg);
    }

    public function clearQueue()
    {
        try {
            Artisan::call('queue:clear', [
                '--queue' => 'disparos',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            return $this->success(null, 'Send queue cleared.');
        } catch (\Exception $e) {
            return $this->error('Failed to clear queue: ' . $e->getMessage(), 500);
        }
    }

    public function clearWarmupQueue()
    {
        try {
            Artisan::call('queue:clear', [
                '--queue' => 'warmup',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            return $this->success(null, 'Warmup queue cleared.');
        } catch (\Exception $e) {
            return $this->error('Failed to clear warmup queue: ' . $e->getMessage(), 500);
        }
    }
}
