<?php

namespace Modules\BLJourney\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class BLJourneyServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'BLJourney';

    protected string $nameLower = 'bljourney';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Register module services and journey configuration.
     */
    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            module_path($this->name, 'config/product_journeys.php'),
            'product_journeys'
        );
    }
}