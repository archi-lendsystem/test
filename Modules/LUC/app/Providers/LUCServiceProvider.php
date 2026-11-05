<?php

namespace Modules\LUC\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Modules\LUC\Console\Commands\FlagOverdueLucCommand;

class LUCServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'LUC';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'luc';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        FlagOverdueLucCommand::class,
    ];

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
     * Define module schedules.
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule
            ->command('luc:flag-overdue')
            ->dailyAt('09:00')
            ->timezone((string) config('app.timezone', 'UTC'))
            ->withoutOverlapping(60);
    }
}
