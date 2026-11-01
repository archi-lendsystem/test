<?php

namespace Modules\DemandNotice\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class DemandNoticeServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'DemandNotice';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'demandnotice';

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
