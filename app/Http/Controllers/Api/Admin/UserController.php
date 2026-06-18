<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends ApiController
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $perPage = min((int) $request->input('per_page', 10), 100);

        $query = User::query();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('email', 'LIKE', "%{$search}%")
                    ->orWhere('username', 'LIKE', "%{$search}%");
            });
        }

        return $this->paginated($query->latest()->paginate($perPage));
    }

    public function show(User $user)
    {
        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'active' => (bool) $user->active,
        ]);
    }

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

        return $this->success($user->fresh(), 'User updated.');
    }

    public function destroy(User $user)
    {
        $user->delete();

        return $this->success(null, 'User deleted.');
    }

    public function toggleActive(User $user)
    {
        $user->active = $user->active == 1 ? 0 : 1;
        $user->save();

        return $this->success(['active' => (bool) $user->active], 'User status updated.');
    }

    public function toggleAdmin(User $user)
    {
        $user->role = $user->role === 'admin' ? 'user' : 'admin';
        $user->save();

        return $this->success(['role' => $user->role], 'Role updated.');
    }
}
