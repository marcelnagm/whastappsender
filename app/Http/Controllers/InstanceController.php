<?php

namespace App\Http\Controllers;

use App\Models\Instance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\WhastappService as WhatsappService;

class InstanceController extends Controller
{
    public function index()
    {
        // Only instances for the signed-in user (admins see all)
        if (auth()->user()->isAdmin())
            $instances = Instance::paginate(10);
        else $instances = auth()->user()->instances()->latest()->paginate(10);

        return view('instances.index', compact('instances'));
    }

    public function create()
    {
        return view('instances.create');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20', // Phone from the form
        ]);

        // Normalize phone into a numeric technical id
        $instanceName = preg_replace('/[^0-9]/', '', $request->phone);

        Auth::user()->instances()->create([
            'name' => $request->name,
            'instance_name' => $instanceName,
            'status' => 'disconnected'
        ]);

        return redirect()->route('instances.index')->with('success', 'Instance registered.');
    }

    public function show(Instance $instance)
    {
        $this->authorizeOwner($instance);
        return view('instances.show', compact('instance'));
    }

    public function destroy(Instance $instance)
    {
        $this->authorizeOwner($instance);

        // Later: call Evolution API DELETE here
        $instance->delete();
        WhatsappService::delete($instance->instance_name);

        return redirect()->route('instances.index')
            ->with('success', 'Instance removed locally.');
    }

    public function warmup(Instance $instance)
    {
        $this->authorizeOwner($instance);

        // Toggle warmup flag (0 or 1)
        $instance->warmup = $instance->warmup == 1 ? 0 : 1;
        $instance->save();

        $statusMsg = $instance->warmup ? 'enabled' : 'disabled';

        return redirect()->route('instances.index')
            ->with('success', "Warmup {$statusMsg} successfully.");
    }

    private function authorizeOwner(Instance $instance)
    {
        if ($instance->user_id !== Auth::id()) {
            abort(403, 'Access denied for this instance.');
        }
    }

    public function sync($id)
    {
        // 1. Ensure the user owns this instance
        $ins = Instance::findOrFail($id);

        // 2. Dispatch sync job with instance name and user id
        if ($ins->isMine())
            \App\Jobs\SyncContactsJob::dispatch(auth()->id(), $ins->instance_name);
        else redirect()->route('instances.index')
            ->with('error', 'This instance does not belong to you.');
        return back()->with('success', "Instance {$id} sync started.");
    }
}
