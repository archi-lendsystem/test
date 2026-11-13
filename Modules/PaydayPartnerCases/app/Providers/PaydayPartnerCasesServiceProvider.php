<?php

namespace Modules\PaydayPartnerCases\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\PaydayPartnerCases\Console\Commands\SyncLoanTrustCasesCommand;

class PaydayPartnerCasesServiceProvider extends ServiceProvider
{
   public function register(): void
    {
        // No manual binding needed — Laravel auto-resolves services.
    }

    public function boot(): void
    {
        $this->registerRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncLoanTrustCasesCommand::class,
            ]);
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('loantrust:sync-cases')
                ->everyThirtyMinutes()
                ->withoutOverlapping()
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/loantrust-sync.log'));
        });
    }

    private function registerRoutes(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(base_path('Modules/PaydayPartnerCases/routes/api.php'));
    }

    

}