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
        // Lista apenas as instâncias do usuário logado
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
            'phone' => 'required|string|max:20', // Recebendo o telefone do formulário
        ]);

        // Limpa o telefone para ser usado como ID técnico (apenas números)
        $instanceName = preg_replace('/[^0-9]/', '', $request->phone);

        Auth::user()->instances()->create([
            'name' => $request->name,
            'instance_name' => $instanceName,
            'status' => 'disconnected'
        ]);

        return redirect()->route('instances.index')->with('success', 'Instância registrada.');
    }

    public function show(Instance $instance)
    {
        $this->authorizeOwner($instance);
        return view('instances.show', compact('instance'));
    }

    public function destroy(Instance $instance)
    {
        $this->authorizeOwner($instance);

        // No futuro, aqui você disparará o DELETE para a Evolution API
        $instance->delete();
        WhatsappService::delete($instance->instance_name);

        return redirect()->route('instances.index')
            ->with('success', 'Instância removida localmente.');
    }

    private function authorizeOwner(Instance $instance)
    {
        if ($instance->user_id !== Auth::id()) {
            abort(403, 'Acesso negado a esta instância.');
        }
    }
}
