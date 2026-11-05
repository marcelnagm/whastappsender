<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\ChatTag;
use App\Models\PipelineStage;
use App\Services\Automation\Automations;
use App\Services\Chat\ChatAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** "When X, do Y" rules of the account. */
class AutomationsController extends Controller
{
    public function __construct(private Automations $engine)
    {
    }

    public function index(Request $request)
    {
        $owner = $request->user();
        $rules = Automation::where('user_id', $owner->id)->orderBy('id')->get();
        $runs = DB::table('automation_runs')->join('automations', 'automations.id', '=', 'automation_runs.automation_id')
            ->where('automation_runs.user_id', $owner->id)->orderByDesc('automation_runs.id')->limit(20)->get(['automation_runs.*', 'automations.name']);

        return view('automations.index', [
            'rules' => $rules, 'runs' => $runs,
            'triggers' => Automations::TRIGGERS, 'actionTypes' => Automations::ACTIONS,
            'stages' => PipelineStage::where('user_id', $owner->id)->orderBy('pipeline_id')->orderBy('position')->get(['id', 'name', 'kind']),
            'tags' => ChatTag::where('user_id', $owner->id)->orderBy('name')->get(['id', 'name']),
            'people' => app(ChatAssignment::class)->people($owner)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]),
            'max' => Automations::MAX_ACTIONS,
        ]);
    }

    public function store(Request $request)
    {
        $owner = $request->user();
        $data = $request->validate(['name' => 'required|string|max:80', 'trigger' => ['required', Rule::in(Automations::TRIGGERS)]]);
        if (Automation::where('user_id', $owner->id)->count() >= Automations::MAX_RULES) {
            return back()->withErrors(['name' => __('Limit of :n automations reached.', ['n' => Automations::MAX_RULES])]);
        }
        $clean = $this->engine->clean($owner, $data['trigger'], $request->all());
        if (!$clean['actions']) {
            return back()->withErrors(['name' => __('Add at least one valid action.')])->withInput();
        }
        if ($data['trigger'] === 'message.keyword' && empty($clean['conditions']['keywords'])) {
            return back()->withErrors(['name' => __('Type at least one word to look for.')])->withInput();
        }
        Automation::create(['user_id' => $owner->id, 'name' => $data['name'], 'trigger' => $data['trigger']] + $clean);

        return back()->with('success', __('Automation saved.'));
    }

    public function toggle(Request $request, int $automation)
    {
        $a = Automation::where('user_id', $request->user()->id)->findOrFail($automation);
        $a->update(['active' => !$a->active]);

        return back()->with('success', __('Saved.'));
    }

    public function destroy(Request $request, int $automation)
    {
        $a = Automation::where('user_id', $request->user()->id)->findOrFail($automation);
        DB::table('automation_runs')->where('automation_id', $a->id)->delete();
        $a->delete();

        return back()->with('success', __('Automation removed.'));
    }
}
