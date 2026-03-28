<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WhatsappJob;

class WhatsappJobController extends Controller
{
    //
    public function index(Request $request, $id)
    {
        // 1. TENTATIVA FLEXÍVEL: Busca por campanha OU por item se o ID for ambíguo
        $query = WhatsappJob::where(function ($q) use ($id) {
            $q->where('campaign_item_id', $id);
        });

        // 2. FILTROS DINÂMICOS (Só entram se o usuário preencher no formulário)
        if ($request->filled('campaign_item_id')) {
            $query->where('campaign_item_id', $request->campaign_item_id);
        }

        if ($request->filled('contact')) {
            $query->where('payload', 'like', '%' . $request->contact . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('evolution_status')) {
            $query->where('evolution_status', $request->evolution_status);
        }

        // 3. DASHBOARD (Calculado antes da paginação para não perder dados)
        // Usamos um clone limpo da query filtrada
        $dashboardQuery = clone $query;

        $statsStatus = $dashboardQuery->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statsEvolution = (clone $query)->whereNotNull('evolution_status')
            ->selectRaw('evolution_status, count(*) as total')
            ->groupBy('evolution_status')
            ->pluck('total', 'evolution_status');

        // 4. EXECUÇÃO FINAL
        $jobs = $query->orderBy('id', 'desc')->paginate(50)->withQueryString();

        // DEBUG INTERNO (Se os dados sumirem, descomente a linha abaixo para ver o SQL)
        // dd($query->toSql(), $query->getBindings());

        return view('whatsapp-jobs.index', compact('jobs', 'id', 'statsStatus', 'statsEvolution'));
    }

    public function retry($id)
    {
        try {
            // 1. Localiza o Job (Garante que só tente re-enviar o que realmente deu erro)
            $job = WhatsappJob::where('id', $id)
                ->where('status', 'erro')
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

            return redirect()->back()->with('success', "O reenvio do Job #{$id} foi solicitado com sucesso.");

        } catch (Exception $e) {
            // Se o Job não for encontrado ou não for status 'erro'
            return redirect()->back()->with('error', "Não foi possível reprocessar este registro. Verifique se ele ainda está como 'erro'.");
        }
    }
}
