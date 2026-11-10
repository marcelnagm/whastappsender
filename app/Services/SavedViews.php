<?php

namespace App\Services;

use App\Models\SavedView;
use App\Models\User;
use App\Services\Team\TeamContext;

/**
 * Saved views: the filters of a list (contacts, follow-ups, funnel) kept under a name, to open again with one click.
 * Only the known filter keys of each page are kept, so a saved view can never carry anything else into a URL.
 */
class SavedViews
{
    /** scope => [route name, allowed query keys] */
    public const SCOPES = [
        'contacts' => ['contacts.index', ['search', 'tier', 'stage', 'sort']],
        'tasks' => ['tasks.index', ['scope', 'tab']],
        'pipeline' => ['pipeline.index', ['pipeline']],
    ];

    public static function clean(string $scope, string $query): string
    {
        parse_str(ltrim($query, '?'), $in);
        $keep = [];
        foreach (self::SCOPES[$scope][1] ?? [] as $k) {
            if (isset($in[$k]) && is_scalar($in[$k]) && trim((string) $in[$k]) !== '') {
                $keep[$k] = mb_substr(trim((string) $in[$k]), 0, 120);
            }
        }

        return http_build_query($keep);
    }

    /** Views the signed-in person can open: the team's shared ones and their own. */
    public static function visible(User $account, string $scope)
    {
        $me = TeamContext::actor()?->id ?? $account->id;

        return SavedView::where('user_id', $account->id)->where('scope', $scope)->where(fn ($q) => $q->where('shared', true)->orWhere('created_by', $me))->orderBy('name')->get();
    }

    public static function url(SavedView $v): string
    {
        $route = self::SCOPES[$v->scope][0] ?? 'home.index';

        return route($route) . ($v->query !== '' ? '?' . $v->query : '');
    }

    /** Whoever created it, or the account owner / an administrator, may delete a view. */
    public static function canDelete(SavedView $v, User $account): bool
    {
        $ctx = app(TeamContext::class);

        return !$ctx->isMember() || (int) $v->created_by === (int) $ctx->member()->id || $ctx->can('team');
    }
}
