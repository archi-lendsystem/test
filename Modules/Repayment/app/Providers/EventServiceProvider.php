<?php

namespace Modules\Repayment\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\BLAmortization\Events\BLAmortizationGenerated;
use Modules\Repayment\Listeners\ActivateRepaymentAccount;


class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
      protected $listen = [
        BLAmortizationGenerated::class => [
            ActivateRepaymentAccount::class,
        ],
      ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = false;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
