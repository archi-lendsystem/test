<?php

namespace Modules\BLSaction\Providers;

use Illuminate\Support\ServiceProvider;

class BLSactionServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'BLSaction';
    protected string $moduleNameLower = 'blSaction'; // ← Must match the namespace used in Pdf::loadView()

    public function boot(): void
    {
        $this->registerViews();
        $this->registerTranslations();
    }

    public function register(): void
{
    $this->app->register(EventServiceProvider::class);
    $this->app->register(RouteServiceProvider::class);
}

    protected function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower . '-module-views']);

        // ✅ This line registers the namespace "blSaction"
        $paths = array_merge(
            $this->getPublishableViewPaths(),
            [$sourcePath]
        );

        $this->loadViewsFrom($paths, 'blSaction');
        $this->loadViewsFrom($paths, 'blsaction');
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);
        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'lang'), $this->moduleNameLower);
        }
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }
        return $paths;
    }
}