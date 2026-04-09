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
            $msg = 'Sistema de disparos retomado com sucesso.';
        } else {
            // Define a flag sem tempo de expiração
            Cache::forever($panicKey, true);
            $msg = 'SISTEMA PAUSADO: Os workers irão ignorar novos processamentos.';
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
            Artisan::call('queue:clear', ['--queue' => 'disparos']);

            return back()->with('success', 'Fila de disparos limpa. Todos os jobs pendentes foram removidos.');
        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao limpar fila: ' . $e->getMessage());
        }
    }

    public function warmup()
    {
        try {
            // Comando para limpar a fila específica
            Artisan::call('queue:clear', ['--queue' => 'warmup']);

            return back()->with('success', 'Fila de disparos limpa. Todos os jobs pendentes foram removidos.');
        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao limpar fila: ' . $e->getMessage());
        }
    }
}
