<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class LanguageController extends Controller
{
    /**
     * Stores the chosen language (session + cookie, and the user profile when logged in)
     * and sends the person back to the page they were on.
     */
    public function switch(Request $request, string $locale)
    {
        abort_unless(array_key_exists($locale, config('languages.supported', [])), 404);

        $request->session()->put(config('languages.session_key'), $locale);
        Cookie::queue(config('languages.cookie'), $locale, config('languages.cookie_minutes'));

        if ($user = $request->user()) {
            $user->forceFill(['locale' => $locale])->save();
        }

        return redirect()->back();
    }
}
