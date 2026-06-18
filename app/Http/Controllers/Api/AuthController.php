<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends ApiController
{
    public function login(LoginRequest $request)
    {
        $credentials = $request->getCredentials();

        if (!Auth::validate($credentials)) {
            return $this->error('Invalid credentials.', 401);
        }

        $user = Auth::getProvider()->retrieveByCredentials($credentials);

        if (!$user->isActive()) {
            return $this->error('Account is disabled.', 403);
        }

        $tokenName = $request->input('token_name', 'agent-api');
        $abilities = $request->input('abilities', ['*']);
        $token = $user->createToken($tokenName, $abilities);

        return $this->success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->formatUser($user),
        ], 'Authenticated successfully.');
    }

    public function me(Request $request)
    {
        return $this->success($this->formatUser($request->user()));
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(null, 'Token revoked.');
    }

    public function tokens(Request $request)
    {
        $tokens = $request->user()->tokens()->get(['id', 'name', 'abilities', 'last_used_at', 'created_at']);

        return $this->success($tokens);
    }

    public function createToken(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'abilities' => 'nullable|array',
        ]);

        $token = $request->user()->createToken(
            $data['name'],
            $data['abilities'] ?? ['*']
        );

        return $this->success([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ], 'Token created.', 201);
    }

    public function revokeToken(Request $request, int $tokenId)
    {
        $deleted = $request->user()->tokens()->where('id', $tokenId)->delete();

        if (!$deleted) {
            return $this->error('Token not found.', 404);
        }

        return $this->success(null, 'Token revoked.');
    }

    private function formatUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role,
            'ai_enabled' => $user->ai_enabled,
            'ai_mode' => $user->ai_mode,
            'ai_model' => $user->ai_model,
            'ai_temperature' => $user->ai_temperature,
            'ai_max_tokens' => $user->ai_max_tokens,
            'ai_system_prompt' => $user->ai_system_prompt,
            'ai_business_hours_only' => $user->ai_business_hours_only,
        ];
    }
}
