<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Pipeline;
use App\Models\PipelineStage;
use App\Services\Pipeline\SalesPipeline;
use App\Services\Forecast\DealForecast;
use Illuminate\Http\Request;

/** Sales funnel: Kanban board of deals, with drag and drop between stages. */
class PipelineController extends Controller
{
    public function __construct(private SalesPipeline $funnel)
    {
    }

    public function index(Request $request)
    {
        $owner = $request->user();
        $pipelines = Pipeline::where('user_id', $owner->id)->orderBy('id')->get();
        $current = $pipelines->firstWhere('id', (int) $request->query('pipeline')) ?: $this->funnel->defaultFor($owner);
        if ($pipelines->doesntContain('id', $current->id)) {
            $pipelines = Pipeline::where('user_id', $owner->id)->orderBy('id')->get();
        }

        $stages = PipelineStage::with(['deals' => fn ($q) => $q->with('contact:id,name,contact')])->where('pipeline_id', $current->id)->orderBy('position')->orderBy('id')->get();

        $deals = $stages->flatMap->deals;
        $forecast = app(DealForecast::class);
        $scores = $forecast->scoreMany($deals);

        return view('pipeline.index', [
            'origins' => app(\App\Services\Reports\SourceReport::class)->known($owner),
            'scores' => $scores,
            'weighted' => $forecast->weightedValue($deals, $scores),
            'pipelines' => $pipelines,
            'pipeline' => $current,
            'stages' => $stages,
            'summary' => $this->funnel->summary($current),
            'contacts' => Contact::where('user_id', $owner->id)->orderBy('name')->limit(300)->get(['id', 'name', 'contact']),
        ]);
    }

    /** Open deals ranked by chance to close, each with what to do next. */
    public function stale(Request $request)
    {
        $owner = $request->user();
        $cfg = \App\Models\StaleDealSetting::for($owner->id);
        $rows = app(\App\Services\Pipeline\StaleDeals::class)->stale($owner->id, max(1, $cfg->days));

        return view('pipeline.stale', compact('cfg', 'rows'));
    }

    public function staleSettings(Request $request)
    {
        $data = $request->validate(['days' => 'required|integer|min:1|max:365']);
        \App\Models\StaleDealSetting::updateOrCreate(['user_id' => $request->user()->id], ['enabled' => $request->boolean('enabled'), 'days' => $data['days']]);

        return back()->with('success', __('Saved.'));
    }

    public function priorities(Request $request)
    {
        $owner = $request->user();
        $deals = Deal::with(['contact:id,name,contact,score', 'stage:id,name'])->where('user_id', $owner->id)->where('status', 'open')->get();
        $forecast = app(DealForecast::class);
        $scores = $forecast->scoreMany($deals);
        // hot first; among equals the larger value, and a customer who is waiting jumps the queue
        $ranked = $deals->sortByDesc(fn ($d) => [($scores[$d->id]['waiting_hours'] ?? 0) >= 1 ? 1 : 0, $scores[$d->id]['score'], (float) $d->value])->values();

        return view('pipeline.priorities', ['deals' => $ranked, 'scores' => $scores, 'weighted' => $forecast->weightedValue($deals, $scores), 'total' => (float) $deals->sum('value')]);
    }

    /** Funnel result per lead origin. */
    public function sources(Request $request)
    {
        $days = in_array((string) $request->query('period'), ['30', '90', '365'], true) ? (int) $request->query('period') : 90;
        $report = app(\App\Services\Reports\SourceReport::class);

        return view('pipeline.sources', ['data' => $report->build($request->user(), now()->subDays($days - 1)->startOfDay(), now()->endOfDay()), 'days' => $days]);
    }

    /** Written recommendation of the AI agent for one deal. */
    public function advice(Request $request, int $deal): \Illuminate\Http\JsonResponse
    {
        $owner = $request->user();
        $d = Deal::with('contact:id,score')->where('user_id', $owner->id)->findOrFail($deal);
        $forecast = app(DealForecast::class);
        $f = $forecast->scoreMany(collect([$d]))[$d->id];
        abort_if($f['score'] === null, 422, __('Only open deals have a forecast.'));

        return response()->json($forecast->advice($d, $owner, $f));
    }

    public function storePipeline(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);
        $p = $this->funnel->create($request->user(), $data['name']);

        return redirect()->route('pipeline.index', ['pipeline' => $p->id])->with('success', __('Pipeline created.'));
    }

    public function destroyPipeline(Request $request, int $pipeline)
    {
        $p = $this->pipeline($request, $pipeline);
        if (Pipeline::where('user_id', $request->user()->id)->count() <= 1) {
            return back()->with('error', __('You need at least one pipeline.'));
        }
        Deal::where('pipeline_id', $p->id)->delete();
        PipelineStage::where('pipeline_id', $p->id)->delete();
        $p->delete();

        return redirect()->route('pipeline.index')->with('success', __('Pipeline removed.'));
    }

    // ---- stages ----------------------------------------------------------------------------------------------------

    public function storeStage(Request $request, int $pipeline)
    {
        $p = $this->pipeline($request, $pipeline);
        $data = $request->validate(['name' => 'required|string|max:80', 'kind' => 'nullable|in:open,won,lost', 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        PipelineStage::create(['user_id' => $p->user_id, 'pipeline_id' => $p->id, 'name' => $data['name'], 'kind' => $data['kind'] ?? 'open',
            'color' => $data['color'] ?? '#6c757d', 'position' => 1 + (int) PipelineStage::where('pipeline_id', $p->id)->max('position')]);

        return redirect()->route('pipeline.index', ['pipeline' => $p->id])->with('success', __('Stage added.'));
    }

    public function updateStage(Request $request, int $stage)
    {
        $s = $this->stage($request, $stage);
        $data = $request->validate(['name' => 'required|string|max:80', 'kind' => 'nullable|in:open,won,lost', 'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/']]);
        $kind = $data['kind'] ?? $s->kind;
        $s->update(['name' => $data['name'], 'kind' => $kind, 'color' => $data['color'] ?? $s->color]);
        Deal::where('stage_id', $s->id)->update(['status' => $kind]); // the deals in it follow the stage

        return redirect()->route('pipeline.index', ['pipeline' => $s->pipeline_id])->with('success', __('Stage saved.'));
    }

    public function destroyStage(Request $request, int $stage)
    {
        $s = $this->stage($request, $stage);
        if (Deal::where('stage_id', $s->id)->exists()) {
            return back()->with('error', __('Move the deals out of this stage first.'));
        }
        $s->delete();

        return redirect()->route('pipeline.index', ['pipeline' => $s->pipeline_id])->with('success', __('Stage removed.'));
    }

    // ---- deals -----------------------------------------------------------------------------------------------------

    public function storeDeal(Request $request, int $pipeline)
    {
        $p = $this->pipeline($request, $pipeline);
        $data = $this->dealData($request);
        $stage = $request->filled('stage_id') ? PipelineStage::where('pipeline_id', $p->id)->findOrFail((int) $request->input('stage_id')) : null;
        $this->funnel->open($p, $data, $stage);

        return redirect()->route('pipeline.index', ['pipeline' => $p->id])->with('success', __('Deal created.'));
    }

    public function updateDeal(Request $request, int $deal)
    {
        $d = $this->deal($request, $deal);
        $data = $this->dealData($request);
        if ($d->items()->exists()) {
            unset($data['value']); // the value of a deal with items is their total
        }
        $d->fill($data)->save();

        return redirect()->route('pipeline.index', ['pipeline' => $d->pipeline_id])->with('success', __('Deal saved.'));
    }

    public function moveDeal(Request $request, int $deal)
    {
        $d = $this->deal($request, $deal);
        $data = $request->validate(['stage_id' => 'required|integer', 'position' => 'nullable|integer|min:0', 'lost_reason' => 'nullable|string|max:255']);
        $stage = PipelineStage::where('pipeline_id', $d->pipeline_id)->findOrFail($data['stage_id']);
        $moved = $this->funnel->move($d, $stage, $data['position'] ?? null, $data['lost_reason'] ?? null);

        return response()->json(['ok' => true, 'status' => $moved->status, 'summary' => $this->funnel->summary(Pipeline::find($moved->pipeline_id))]);
    }

    public function destroyDeal(Request $request, int $deal)
    {
        $d = $this->deal($request, $deal);
        $d->delete();

        return redirect()->route('pipeline.index', ['pipeline' => $d->pipeline_id])->with('success', __('Deal removed.'));
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function dealData(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:160',
            'value' => 'nullable|numeric|min:0|max:9999999999',
            'contact_id' => 'nullable|integer',
            'expected_close_date' => 'nullable|date',
            'origin' => 'nullable|string|max:80',
        ]);
        $data['value'] = $data['value'] ?? 0;
        $data['contact_id'] = !empty($data['contact_id']) && Contact::where('user_id', $request->user()->id)->where('id', $data['contact_id'])->exists() ? (int) $data['contact_id'] : null;

        return $data;
    }

    private function pipeline(Request $request, int $id): Pipeline
    {
        return Pipeline::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function stage(Request $request, int $id): PipelineStage
    {
        return PipelineStage::where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function deal(Request $request, int $id): Deal
    {
        return Deal::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
