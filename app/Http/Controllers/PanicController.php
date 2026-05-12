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
            // Define a flag sem tempo de expiração
            Cache::forever($panicKey, true);
            $msg = 'SYSTEM PAUSED: Workers will skip new processing.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Limpa fisicamente a fila do driver (Redis/Database)
     */
    public function clear()
    {
        try {
            // Comando para limpar a fila específica
            Artisan::call('queue:clear', [
                '--queue' => 'disparos',
                '--force' => true,      // Ignora a pergunta de confirmação
                '--no-interaction' => true // Garante que o Symfony Console não busque o STDIN
            ]);

            return back()->with('success', 'Send queue cleared. All pending jobs were removed.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to clear queue: ' . $e->getMessage());
        }
    }

    public function warmup()
    {
        try {
            // Comando para limpar a fila específica
            Artisan::call('queue:clear', [
                '--queue' => 'warmup',
                '--force' => true,      // Ignora a pergunta de confirmação
                '--no-interaction' => true // Garante que o Symfony Console não busque o STDIN
            ]);
            return back()->with('success', 'Warmup queue cleared. All pending jobs were removed.');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to clear queue: ' . $e->getMessage());
        }
    }
}
