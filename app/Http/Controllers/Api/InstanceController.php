<?php

namespace App\Http\Controllers\Api;

use App\Models\Instance;
use App\WhastappService as WhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InstanceController extends ApiController
{
    public function index(Request $request)
    {
        $perPage = min((int) $request->input('per_page', 10), 100);

        $query = Auth::user()->isAdmin()
            ? Instance::query()
            : Auth::user()->instances();

        $instances = $query->latest()->paginate($perPage);

        return $this->paginated($instances);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
        ]);

        $instanceName = preg_replace('/[^0-9]/', '', $data['phone']);

        $instance = Auth::user()->instances()->create([
            'name' => $data['name'],
            'instance_name' => $instanceName,
            'status' => 'disconnected',
        ]);

        return $this->success($instance, 'Instance created.', 201);
    }

    public function show(Instance $instance)
    {
        $this->authorizeOwner($instance);

        return $this->success([
            'instance' => $instance,
            'connected' => WhatsappService::isConnected($instance->instance_name),
        ]);
    }

    public function destroy(Instance $instance)
    {
        $this->authorizeOwner($instance);

        WhatsappService::delete($instance->instance_name);
        $instance->delete();

        return $this->success(null, 'Instance deleted.');
    }

    public function qr(Instance $instance)
    {
        $this->authorizeOwner($instance);

        $qr = WhatsappService::qr($instance->instance_name);

        if ($qr === false) {
            return $this->error('Could not fetch QR code from Evolution API.', 502);
        }

        return $this->success($qr);
    }

    public function connection(Instance $instance)
    {
        $this->authorizeOwner($instance);

        $connected = WhatsappService::isConnected($instance->instance_name);

        if ($connected && $instance->status !== 'connected') {
            $instance->update(['status' => 'connected']);
        }

        return $this->success([
            'instance_id' => $instance->id,
            'instance_name' => $instance->instance_name,
            'connected' => $connected,
            'status' => $instance->fresh()->status,
        ]);
    }

    public function toggleWarmup(Instance $instance)
    {
        $this->authorizeOwner($instance);

        $instance->warmup = $instance->warmup == 1 ? 0 : 1;
        $instance->save();

        return $this->success([
            'warmup' => (bool) $instance->warmup,
        ], 'Warmup ' . ($instance->warmup ? 'enabled' : 'disabled') . '.');
    }

    public function sync(Instance $instance)
    {
        $this->authorizeOwner($instance);

        \App\Jobs\SyncContactsJob::dispatch(Auth::id(), $instance->instance_name);

        return $this->success([
            'instance_id' => $instance->id,
        ], 'Contact sync started.');
    }
}
