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
     * Remove multiple records in one go.
     * Mass delete to avoid N+1 delete queries.
     */
    public function bulkDelete(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', 'No items selected for removal.');
        }

        try {
            // Delete rows owned by the user (or all if admin)
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
     * Re-queue selected failed jobs.
     * Updates status and dispatches each job so backoff / retries apply correctly.
     */
    public function bulkRetry(Request $request)
    {
        $ids = json_decode($request->input('ids'), true);

        if (!$ids || !is_array($ids)) {
            return redirect()->back()->with('error', 'No items selected for retry.');
        }

        try {
            $jobs = WhatsappJob::whereIn('id', $ids)
                ->where('status', 'erro') // Only rows that actually failed
                ->get();

            if ($jobs->isEmpty()) {
                return redirect()->back()->with('warning', 'No jobs in error state were found in your selection.');
            }

            foreach ($jobs as $job) {
                // 1. Clear previous failure metadata
                $job->update([
                    'status' => 'fila',
                    'erro_mensagem' => null,
                    'evolution_status' => null
                ]);

                // 2. Push back onto the send queue
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
        // 1. Flexible search
        $query = WhatsappJob::with(['contact', 'campaignItem'])->where('campaign_item_id', $id);
    
        // 2. Dynamic filters
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
    
        // 3. Dashboard aggregates (clone query so filters stay intact)
        $dashboardQuery = clone $query;
        $statsStatus = $dashboardQuery->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    
        $statsEvolution = (clone $query)->whereNotNull('evolution_status')
            ->selectRaw('evolution_status, count(*) as total')
            ->groupBy('evolution_status')
            ->pluck('total', 'evolution_status');
    
        // 4. Paginated listing
        $jobs = $query->orderBy('id', 'desc')->paginate(50)->withQueryString();
    
        return view('whatsapp-jobs.index', compact('jobs', 'id', 'statsStatus', 'statsEvolution'));
    }
    

    public function retry($id)
    {
        try {
            // 1. Load job (only failed or queued rows)
            $job = WhatsappJob::where('id', $id)
                ->whereIn('status', ['erro', 'fila'])
                ->firstOrFail();

            // 2. Reset state / error trace
            $job->update([
                'status' => 'pendente',
                'erro_mensagem' => null, // Clear old error so UI/logs stay clear
                'evolution_status' => null,
                'updated_at' => now()
            ]);

            // 3. Dispatch to workers
            EnviarMensagemJob::dispatch($job)->onQueue('disparos');

            return redirect()->back()->with('success', "Retry for job #{$id} has been queued.");
        } catch (Exception $e) {
            // Job missing or not in a retryable state
            return redirect()->back()->with('error', "Could not reprocess this record. Make sure it is still in 'erro' status.");
        }
    }
}
