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
        return back()->with('success', 'Status do usuário atualizado!');
    }

    // Alterar Role (Admin/User)
    public function toggleAdmin(User $user)
    {
        $newRole = $user->role === 'admin' ? 'user' : 'admin';
        $user->role = $newRole;
        $user->save();
        return back()->with('success', 'Permissão alterada com sucesso!');
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
        return redirect()->route('users.index')->with('success', 'Usuário atualizado!');
    }

    // Exclusão (Cuidado aqui)
    public function destroy(User $user)
    {
        $user->delete();
        return back()->with('success', 'Usuário removido do sistema.');
    }
}
