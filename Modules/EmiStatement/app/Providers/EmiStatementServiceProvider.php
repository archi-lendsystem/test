<?php

namespace Modules\EmiStatement\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class EmiStatementServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'EmiStatement';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'emistatement';

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
