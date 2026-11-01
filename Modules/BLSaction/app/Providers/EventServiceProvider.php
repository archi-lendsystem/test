<?php

namespace Modules\BLSaction\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\BLSaction\Listeners\MarkSanctionAsESigned;
use Modules\ESign\Events\ESignCompleted;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        ESignCompleted::class => [
            MarkSanctionAsESigned::class,
        ],
    ];

    /**
     * Explicit registration is being used.
     */
    protected static $shouldDiscoverEvents = false;

    protected function configureEmailVerification(): void
    {
    }
}