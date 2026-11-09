<?php

namespace Modules\Repayment\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\Repayment\Console\Commands\LevyPenalChargesCommand;
use Modules\Repayment\Console\Commands\BackfillPaidRepaymentDueStatusesCommand;
use Modules\Repayment\Console\Commands\RefreshRepaymentDueStatusesCommand;
use Modules\Repayment\Console\Commands\RetryRepaymentActivationsCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class RepaymentServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Repayment';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'repayment';

    /**
     * Repayment Artisan commands.
     *
     * @var array<int, class-string>
     */
    protected array $commands = [
        RefreshRepaymentDueStatusesCommand::class,
        RetryRepaymentActivationsCommand::class,
        LevyPenalChargesCommand::class,
        BackfillPaidRepaymentDueStatusesCommand::class,
    ];

    /**
     * Repayment module providers.
     *
     * @var array<int, class-string>
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Configure Repayment scheduled processes.
     */
    protected function configureSchedules(
        Schedule $schedule
    ): void {
        $schedule
            ->command('repayment:refresh-due-statuses')
            ->dailyAt('00:15')
            ->timezone(
                (string) config(
                    'app.timezone',
                    'UTC'
                )
            )
            ->withoutOverlapping(60);

        // Runs after the due-status refresh above so overdue_amount/DPD
        // are current before penal charges are computed off them.
        $schedule
            ->command('repayment:levy-penal-charges')
            ->dailyAt('00:30')
            ->timezone(
                (string) config(
                    'app.timezone',
                    'UTC'
                )
            )
            ->withoutOverlapping(60);
    }
}


// namespace Modules\Repayment\Providers;

// use Illuminate\Console\Scheduling\Schedule;
// use Modules\Repayment\Console\Commands\LevyPenalChargesCommand;
// use Modules\Repayment\Console\Commands\RefreshRepaymentDueStatusesCommand;
// use Modules\Repayment\Console\Commands\RetryRepaymentActivationsCommand;
// use Nwidart\Modules\Support\ModuleServiceProvider;

// class RepaymentServiceProvider extends ModuleServiceProvider
// {
//     /**
//      * The name of the module.
//      */
//     protected string $name = 'Repayment';

//     /**
//      * The lowercase version of the module name.
//      */
//     protected string $nameLower = 'repayment';

//     /**
//      * Repayment Artisan commands.
//      *
//      * @var array<int, class-string>
//      */
//     protected array $commands = [
//         RefreshRepaymentDueStatusesCommand::class,
//         RetryRepaymentActivationsCommand::class,
//         LevyPenalChargesCommand::class,
//     ];

//     /**
//      * Repayment module providers.
//      *
//      * @var array<int, class-string>
//      */
//     protected array $providers = [
//         EventServiceProvider::class,
//         RouteServiceProvider::class,
//     ];

//     /**
//      * Configure Repayment scheduled processes.
//      */
//     protected function configureSchedules(
//         Schedule $schedule
//     ): void {
//         $schedule
//             ->command('repayment:refresh-due-statuses')
//             ->dailyAt('00:15')
//             ->timezone(
//                 (string) config(
//                     'app.timezone',
//                     'UTC'
//                 )
//             )
//             ->withoutOverlapping(60);

//         // Runs after the due-status refresh above so overdue_amount/DPD
//         // are current before penal charges are computed off them.
//         $schedule
//             ->command('repayment:levy-penal-charges')
//             ->dailyAt('00:30')
//             ->timezone(
//                 (string) config(
//                     'app.timezone',
//                     'UTC'
//                 )
//             )
//             ->withoutOverlapping(60);
//     }
// }
