<?php

namespace Modules\CollectionReport\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class CollectionReportServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'CollectionReport';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'collectionreport';

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
