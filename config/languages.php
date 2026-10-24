<?php

/*
|--------------------------------------------------------------------------
| Supported Languages
|--------------------------------------------------------------------------
|
| Every key must match a translation file in /lang (for example lang/pt_BR.json
| and the lang/pt_BR/ folder). To add a language, add an entry here and create
| its translation files; the selector picks it up automatically.
|
| name   : English name (used in aria-labels / tooltips)
| native : name shown to the user in the selector
| flag   : emoji shown next to the name
| html   : value for <html lang="..."> (BCP 47)
| carbon : locale used by Carbon for dates (diffForHumans, translatedFormat...)
|
*/

return [

    // Used when nothing else can be detected (user, session, cookie, browser).
    'default' => env('APP_LOCALE', 'en'),

    'supported' => [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'flag' => '🇺🇸',
            'html' => 'en',
            'carbon' => 'en',
        ],
        'pt_BR' => [
            'name' => 'Portuguese (Brazil)',
            'native' => 'Português (Brasil)',
            'flag' => '🇧🇷',
            'html' => 'pt-BR',
            'carbon' => 'pt_BR',
        ],
        'es' => [
            'name' => 'Spanish',
            'native' => 'Español',
            'flag' => '🇪🇸',
            'html' => 'es',
            'carbon' => 'es',
        ],
    ],

    'session_key' => 'locale',

    // Cookie that remembers the choice of guests (1 year).
    'cookie' => 'locale',
    'cookie_minutes' => 525600,
];
