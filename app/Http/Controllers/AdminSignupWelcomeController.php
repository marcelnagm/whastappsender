<?php

namespace App\Http\Controllers;

use App\Models\CampaignItem;
use App\Services\Settings\SignupWelcome;
use Illuminate\Http\Request;

/** Admin > Signup welcome: which campaign message is sent to people who register on the site. */
class AdminSignupWelcomeController extends Controller
{
    public function edit(SignupWelcome $welcome)
    {
        return view('admin.welcome.edit', [
            'items' => CampaignItem::with('user:id,name')->orderBy('user_id')->orderBy('name')->get(),
            'currentId' => $welcome->itemId(),
            'current' => $welcome->item(),
            'envFallback' => $welcome->usesEnvFallback(),
        ]);
    }

    public function update(Request $request, SignupWelcome $welcome)
    {
        $data = $request->validate(['item_id' => 'nullable|integer|exists:campaign_item,id']);
        $welcome->save($data['item_id'] ?? null);

        return redirect()->route('admin.welcome.edit')->with('success', ($data['item_id'] ?? null) ? __('Signup welcome message saved.') : __('Signup welcome message turned off.'));
    }
}
