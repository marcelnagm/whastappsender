<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    // Listagem com filtro simples
    public function index(Request $request)
    {
        // 1. Captura o termo de busca do input 'search'
        $search = $request->input('search');

        // 2. Inicia a Query
        $query = User::query();

        // 3. Aplica o filtro se houver algo escrito
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%");
            });
        }

        // 4. Paginação (Importante: use o appends para não perder a busca ao mudar de página)
        $users = $query->latest()->paginate(10)->appends(['search' => $search]);

        return view('admin.users.index', compact('users'));
    }   

    // Alternar status Ativo/Inativo
    public function toggleActive(User $user)
    {
        $user->active = $user->active  == 1 ? 0 : 1;
        $user->save();
        return back()->with('success', 'User status updated.');
    }

    // Alterar Role (Admin/User)
    public function toggleAdmin(User $user)
    {
        $newRole = $user->role === 'admin' ? 'user' : 'admin';
        $user->role = $newRole;
        $user->save();
        return back()->with('success', 'Role updated successfully.');
    }

    // Formulário de Edição
    public function edit(User $user)
    {
        return view('admin.users.edit', compact('user'));
    }

    // Update dos dados
    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'username' => 'required|unique:users,username,' . $user->id,
            'password' => 'nullable|min:6|confirmed',
        ]);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        } else {
            unset($data['password']);
        }

        $user->update($data);
        return redirect()->route('users.index')->with('success', 'User updated.');
    }

    public function editProfile(Request $request)
    {
        $user = $request->user();
        return view('users.profile', compact('user'));
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
            'username' => 'required|string|max:255|unique:users,username,' . $user->id,
            'password' => 'nullable|min:6|confirmed',
            'ai_enabled' => 'nullable|boolean',
            'ai_mode' => 'required|in:off,assist,auto',
            'ai_model' => 'nullable|string|max:120',
            'ai_temperature' => 'nullable|numeric|min:0|max:2',
            'ai_max_tokens' => 'nullable|integer|min:50|max:8000',
            'ai_system_prompt' => 'nullable|string|max:8000',
            'ai_business_hours_only' => 'nullable|boolean',
        ]);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        } else {
            unset($data['password']);
        }

        $data['ai_enabled'] = $request->boolean('ai_enabled');
        $data['ai_business_hours_only'] = $request->boolean('ai_business_hours_only');
        $data['ai_model'] = trim((string) ($data['ai_model'] ?? '')) ?: 'llama-3.3-70b-versatile';
        $data['ai_temperature'] = isset($data['ai_temperature']) ? (float) $data['ai_temperature'] : 0.7;
        $data['ai_max_tokens'] = isset($data['ai_max_tokens']) ? (int) $data['ai_max_tokens'] : 1024;

        $user->update($data);

        return redirect()->route('profile.edit')->with('success', 'Profile and AI settings updated successfully.');
    }

    // Exclusão (Cuidado aqui)
    public function destroy(User $user)
    {
        $user->delete();
        return back()->with('success', 'User removed from the system.');
    }
}
