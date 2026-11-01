<?php

namespace Modules\CollectionReport\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<string, array<int, string>>
     */
    protected $listen = [];

    protected static $shouldDiscoverEvents = true;
}
