<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait AuthorizesOwnership
{
    protected function authorizeOwner(Model $model, string $userIdColumn = 'user_id'): void
    {
        if (Auth::user()->isAdmin()) {
            return;
        }

        if ($model->{$userIdColumn} !== Auth::id()) {
            abort(403, 'Access denied.');
        }
    }

    protected function scopedToUser($query, string $userIdColumn = 'user_id')
    {
        if (!Auth::user()->isAdmin()) {
            $query->where($userIdColumn, Auth::id());
        }

        return $query;
    }
}
