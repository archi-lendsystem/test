<?php

namespace Modules\DemandReport\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class DemandReportServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'DemandReport';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'demandreport';

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
