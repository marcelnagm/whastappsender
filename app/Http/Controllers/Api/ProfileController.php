<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ProfileController extends ApiController
{
    public function show(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'ai_enabled' => $user->ai_enabled,
            'ai_mode' => $user->ai_mode,
            'ai_model' => $user->ai_model,
            'ai_temperature' => $user->ai_temperature,
            'ai_max_tokens' => $user->ai_max_tokens,
            'ai_system_prompt' => $user->ai_system_prompt,
            'ai_business_hours_only' => $user->ai_business_hours_only,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|unique:users,email,' . $user->id,
            'username' => 'sometimes|required|string|max:255|unique:users,username,' . $user->id,
            'password' => 'nullable|min:6|confirmed',
            'ai_enabled' => 'nullable|boolean',
            'ai_mode' => 'sometimes|required|in:off,assist,auto',
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

        if ($request->has('ai_enabled')) {
            $data['ai_enabled'] = $request->boolean('ai_enabled');
        }
        if ($request->has('ai_business_hours_only')) {
            $data['ai_business_hours_only'] = $request->boolean('ai_business_hours_only');
        }
        if (isset($data['ai_model'])) {
            $data['ai_model'] = trim((string) $data['ai_model']) ?: 'llama-3.3-70b-versatile';
        }

        $user->update($data);

        return $this->success($user->fresh(), 'Profile updated.');
    }
}
