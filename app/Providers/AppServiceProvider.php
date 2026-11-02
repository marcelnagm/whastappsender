<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // who is really signed in (a team member works on the owner's account)
        $this->app->singleton(\App\Services\Team\TeamContext::class);

        if ($this->app->environment('local') && class_exists(\App\Providers\TelescopeServiceProvider::class)) {
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    public function boot()
    {
        // @teamcan('chat') ... @endteamcan : true for the account owner, role-based for team members ('owner' = owner only)
        \Illuminate\Support\Facades\Blade::if('teamcan', fn ($permission) => app(\App\Services\Team\TeamContext::class)->can($permission));

        if (config('app.env') === 'production' || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
    }
}
