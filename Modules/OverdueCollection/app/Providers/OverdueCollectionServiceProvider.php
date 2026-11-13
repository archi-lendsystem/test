<?php

namespace Modules\OverdueCollection\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class OverdueCollectionServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'OverdueCollection';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'overduecollection';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
}
