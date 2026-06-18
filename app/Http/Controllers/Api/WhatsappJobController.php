<?php

namespace App\Http\Controllers\Api;

use App\Jobs\EnviarMensagemJob;
use App\Models\WhatsappJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsappJobController extends ApiController
{
    public function index(Request $request, int $campaignItemId)
    {
        $query = WhatsappJob::with(['contact:id,name,contact', 'campaignItem:id,name'])
            ->where('campaign_item_id', $campaignItemId);

        if (!Auth::user()->isAdmin()) {
            $query->where('user_id', Auth::id());
        }

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

        $statsQuery = clone $query;
        $statsStatus = (clone $statsQuery)->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $statsEvolution = (clone $statsQuery)->whereNotNull('evolution_status')
            ->selectRaw('evolution_status, count(*) as total')
            ->groupBy('evolution_status')
            ->pluck('total', 'evolution_status');

        $perPage = min((int) $request->input('per_page', 50), 100);
        $jobs = $query->orderBy('id', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $jobs->items(),
            'stats' => [
                'status' => $statsStatus,
                'evolution_status' => $statsEvolution,
            ],
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page' => $jobs->lastPage(),
                'per_page' => $jobs->perPage(),
                'total' => $jobs->total(),
            ],
        ]);
    }

    public function retry(int $id)
    {
        $query = WhatsappJob::where('id', $id)->whereIn('status', ['erro', 'fila']);
        $query = $this->scopedToUser($query);
        $job = $query->firstOrFail();

        $job->update([
            'status' => 'pendente',
            'erro_mensagem' => null,
            'evolution_status' => null,
            'updated_at' => now(),
        ]);

        EnviarMensagemJob::dispatch($job)->onQueue('disparos');

        return $this->success($job->fresh(), "Retry for job #{$id} queued.");
    }

    public function bulkRetry(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $jobs = $this->scopedToUser(WhatsappJob::whereIn('id', $data['ids']))
            ->where('status', 'erro')
            ->get();

        if ($jobs->isEmpty()) {
            return $this->error('No jobs in error state found.', 422);
        }

        foreach ($jobs as $job) {
            $job->update([
                'status' => 'fila',
                'erro_mensagem' => null,
                'evolution_status' => null,
            ]);
            EnviarMensagemJob::dispatch($job)->onQueue('disparos');
        }

        return $this->success(['retried' => $jobs->count()], "{$jobs->count()} job(s) re-queued.");
    }

    public function bulkDelete(Request $request)
    {
        $data = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $deleted = $this->scopedToUser(WhatsappJob::whereIn('id', $data['ids']))->delete();

        return $this->success(['deleted' => $deleted], "{$deleted} job(s) removed.");
    }
}
