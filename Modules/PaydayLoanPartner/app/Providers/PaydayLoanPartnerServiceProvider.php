<?php

namespace Modules\PaydayLoanPartner\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\PaydayLoanPartner\Console\GeneratePartnerPullCredentialsCommand;
use Illuminate\Console\Scheduling\Schedule;

class PaydayLoanPartnerServiceProvider extends ModuleServiceProvider
{
     /**
     * The name of the module.
     */
    protected string $name = 'PaydayLoanPartner';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'paydayloanpartner';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        GeneratePartnerPullCredentialsCommand::class,
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
     *
     * @param Schedule $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
