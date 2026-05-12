<?php

namespace App\Http\Controllers;

use App\Jobs\EnviarMensagemJob;
use Illuminate\Http\Request;
use App\Models\WhatsappJob;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class WhatsappJobController extends Controller
{
    //
    /**
     * Remove múltiplos registros em uma única transação.
     * Estratégia: Mass Delete para evitar N+1 queries de delete.
     */
    public function bulkDelete(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', 'No items selected for removal.');
        }

        try {
            // Deleta os registros que pertencem ao usuário (ou todos se for admin)
            $query = WhatsappJob::whereIn('id', $ids);

            if (Auth::user()->role !== 'admin') {
                $query->where('user_id', Auth::id());
            }

            $count = $query->delete();

            return redirect()->back()->with('success', "{$count} record(s) removed successfully.");
        } catch (\Exception $e) {
            Log::error("Bulk delete error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to remove selected records.');
        }
    }

    /**
     * Reinfileira os jobs selecionados.
     * Estratégia: Atualiza o status e despacha para a Queue individualmente para garantir o backoff.
     */
    public function bulkRetry(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', 'No items selected for retry.');
        }

        try {
            $jobs = WhatsappJob::whereIn('id', $ids)
                ->where('status', 'erro') // Segurança: Só retenta o que de fato falhou
                ->get();

            if ($jobs->isEmpty()) {
                return redirect()->back()->with('warning', 'No jobs in error state were found in your selection.');
            }

            foreach ($jobs as $job) {
                // 1. Limpa o rastro do erro anterior
                $job->update([
                    'status' => 'fila',
                    'erro_mensagem' => null,
                    'evolution_status' => null
                ]);

                // 2. Despacha novamente para a fila (Queue)
                // Substitua 'EnviarMensagemJob' pelo nome real da sua classe de Job
                \App\Jobs\EnviarMensagemJob::dispatch($job)->onQueue('disparos');
            }

            return redirect()->back()->with('success', "{$jobs->count()} job(s) re-queued for processing.");
        } catch (\Exception $e) {
            Log::error("Bulk retry error: " . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to process bulk retry.');
        }
    }


    public function index(Request $request, $id)
    {
        // 1. BUSCA FLEXÍVEL
        $query = WhatsappJob::with(['contact', 'campaignItem'])->where('campaign_item_id', $id);
    
        // 2. FILTROS DINÂMICOS
        if ($request->filled('contact')) {
            $query->whereHas('contact', function ($q) use ($request) {
                $q->where('contact', 'like', '%' . $request->contact . '%')
                    ->orWhere('name', 'like', '%' . $request->contact . '%');
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('evolution_status')) {
            $query->where('evolution_status', $request->evolution_status);
        }
    
        // 3. DASHBOARD (Cálculo com Clones)
        $dashboardQuery = clone $query;
        $statsStatus = $dashboardQuery->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    
        $statsEvolution = (clone $query)->whereNotNull('evolution_status')
            ->selectRaw('evolution_status, count(*) as total')
            ->groupBy('evolution_status')
            ->pluck('total', 'evolution_status');
    
        // 4. EXECUÇÃO
        $jobs = $query->orderBy('id', 'desc')->paginate(50)->withQueryString();
    
        return view('whatsapp-jobs.index', compact('jobs', 'id', 'statsStatus', 'statsEvolution'));
    }
    

    public function retry($id)
    {
        try {
            // 1. Localiza o Job (Garante que só tente re-enviar o que realmente deu erro)
            $job = WhatsappJob::where('id', $id)
                ->whereIn('status', ['erro', 'fila'])
                ->firstOrFail();

            // 2. Limpeza de rastro e Reset de Estado
            $job->update([
                'status' => 'pendente',
                'erro_mensagem' => null, // Limpa o erro anterior para não confundir o usuário
                'evolution_status' => null,
                'updated_at' => now()
            ]);

            // 3. Reinjeção na Fila (A verdade útil: O worker precisa ser avisado)
            EnviarMensagemJob::dispatch($job)->onQueue('disparos');

            return redirect()->back()->with('success', "Retry for job #{$id} has been queued.");
        } catch (Exception $e) {
            // Se o Job não for encontrado ou não for status 'erro'
            return redirect()->back()->with('error', "Could not reprocess this record. Make sure it is still in 'erro' status.");
        }
    }
}
