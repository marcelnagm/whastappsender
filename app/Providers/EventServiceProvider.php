<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        \App\Events\Billing\InvoiceIssued::class => [
            [\App\Listeners\SyncGatewayRecurrence::class, 'onInvoiceIssued'],
        ],
        \App\Events\Billing\InvoicePaid::class => [
            [\App\Listeners\SyncGatewayRecurrence::class, 'onInvoicePaid'],
        ],
        \App\Events\Billing\SubscriptionStopped::class => [
            [\App\Listeners\SyncGatewayRecurrence::class, 'onSubscriptionStopped'],
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents()
    {
        return false;
    }
}
