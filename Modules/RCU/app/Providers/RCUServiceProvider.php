<?php

namespace Modules\RCU\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class RCUServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'RCU';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'rcu';

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
