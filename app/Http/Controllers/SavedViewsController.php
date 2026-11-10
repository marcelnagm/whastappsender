<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use App\Services\SavedViews;
use App\Services\Team\TeamContext;
use Illuminate\Http\Request;

class SavedViewsController extends Controller
{
    public function store(Request $request)
    {
        $d = $request->validate(['scope' => 'required|in:' . implode(',', array_keys(SavedViews::SCOPES)), 'name' => 'required|string|max:80', 'query' => 'nullable|string|max:1500']);
        $account = $request->user();
        abort_if(SavedView::where('user_id', $account->id)->count() >= 100, 422, __('Too many saved views. Delete some first.'));

        $query = SavedViews::clean($d['scope'], (string) ($d['query'] ?? ''));
        SavedView::updateOrCreate(
            ['user_id' => $account->id, 'scope' => $d['scope'], 'name' => trim($d['name']), 'created_by' => TeamContext::actor($request)?->id],
            ['query' => $query, 'shared' => $request->boolean('shared')]
        );

        return redirect(route(SavedViews::SCOPES[$d['scope']][0]) . ($query !== '' ? '?' . $query : ''))->with('success', __('View saved.'));
    }

    public function destroy(Request $request, SavedView $view)
    {
        abort_unless((int) $view->user_id === (int) $request->user()->id && SavedViews::canDelete($view, $request->user()), 404);
        $scope = $view->scope;
        $view->delete();

        return redirect()->route(SavedViews::SCOPES[$scope][0])->with('success', __('View deleted.'));
    }
}
