<?php

namespace Modules\ESign\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\ESign\Services\ESignWorkflow;
use Modules\ESign\Console\PruneESignAuditCommand;
use Modules\ESign\Console\SyncPendingESignSessionsCommand;
use Modules\ESign\Contracts\ESignProvider;
use Throwable;

final class ESignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/config.php',
            'esign'
        );

        $this->app->singleton(
            ESignProvider::class,
            function ($app): ESignProvider {
                $class = (string) config(
                    'esign.provider_class'
                );

                $provider = $app->make($class);

                if (! $provider instanceof ESignProvider) {
                    throw new InvalidArgumentException(
                        "Configured eSign provider [{$class}] "
                        .'must implement ESignProvider.'
                    );
                }

                return $provider;
            }
        );
    }

    public function boot(): void
    {
        $this->configureAuthorization();

        $this->loadMigrationsFrom(
            __DIR__.'/../../database/migrations'
        );

        if ((bool) config('esign.enabled', true)) {
            $this->loadRoutesFrom(
                __DIR__.'/../../routes/api.php'
            );
        }

        $this->configureRateLimiters();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncPendingESignSessionsCommand::class,
                PruneESignAuditCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../../config/config.php' =>
                    config_path('esign.php'),
            ], 'esign-config');
        }
    }

    // private function configureAuthorization(): void
    // {
    //     $ability = trim((string) config(
    //         'esign.authorization.backend_ability',
    //         'manage-esign'
    //     ));

    //     if ($ability === '' || Gate::has($ability)) {
    //         return;
    //     }

    //     Gate::define(
    //         $ability,
    //         function ($user) use ($ability): bool {
    //             /*
    //              * Allow users who have the manage-esign permission
    //              * through Spatie Laravel Permission.
    //              */
    //             if (method_exists($user, 'hasPermissionTo')) {
    //                 try {
    //                     if ($user->hasPermissionTo($ability)) {
    //                         return true;
    //                     }
    //                 } catch (Throwable) {
    //                     // Try role and attribute authorization below.
    //                 }
    //             }

    //             /*
    //              * Allow configured backend roles when the User model
    //              * uses Spatie HasRoles or a compatible method.
    //              */
    //             $allowedRoles = (array) config(
    //                 'esign.authorization.backend_roles',
    //                 []
    //             );

    //             if (
    //                 $allowedRoles !== []
    //                 && method_exists($user, 'hasAnyRole')
    //             ) {
    //                 try {
    //                     if ($user->hasAnyRole($allowedRoles)) {
    //                         return true;
    //                     }
    //                 } catch (Throwable) {
    //                     // Try direct model attributes below.
    //                 }
    //             }

    //             /*
    //              * Support applications that keep one role directly
    //              * on the authenticated user model.
    //              */
    //             $role = strtolower(trim((string) (
    //                 $user->getAttribute('role')
    //                 ?? $user->getAttribute('user_type')
    //                 ?? $user->getAttribute('type')
    //                 ?? ''
    //             )));

    //             $normalizedRoles = array_map(
    //                 static fn (mixed $value): string =>
    //                     strtolower(trim((string) $value)),
    //                 $allowedRoles
    //             );

    //             if (
    //                 $role !== ''
    //                 && in_array($role, $normalizedRoles, true)
    //             ) {
    //                 return true;
    //             }

    //             /*
    //              * Support common boolean admin fields.
    //              */
    //             foreach ([
    //                 'is_super_admin',
    //                 'is_admin',
    //                 'is_backend_admin',
    //             ] as $attribute) {
    //                 if ((bool) $user->getAttribute($attribute)) {
    //                     return true;
    //                 }
    //             }

    //             return false;
    //         }
    //     );
    // }


    private function configureAuthorization(): void
{
    $ability = trim((string) config(
        'esign.authorization.backend_ability',
        'manage-esign'
    ));

    if ($ability === '' || Gate::has($ability)) {
        return;
    }

    Gate::define($ability, function ($user): bool {

        /*
        |--------------------------------------------------------------------------
        | 1. Confirm this is a backend user
        |--------------------------------------------------------------------------
        |
        | Your User model uses user_type_id instead of:
        | type = backend
        |
        | Based on your backend administrator seeder,
        | user_type_id = 1 represents these backend users.
        |
        */

        $userTypeId = (int) $user->getAttribute('user_type_id');

        if ($userTypeId !== 1) {
            return false;
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Existing application permission
        |--------------------------------------------------------------------------
        */

        if (
            Gate::has('manage_loan_documents')
            && Gate::forUser($user)->allows('manage_loan_documents')
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 3. Dedicated eSign permission if your app has one
        |--------------------------------------------------------------------------
        */

        if (
            Gate::has('manage_esign')
            && Gate::forUser($user)->allows('manage_esign')
        ) {
            return true;
        }

        /*
        |--------------------------------------------------------------------------
        | 4. Check your actual user_designation field
        |--------------------------------------------------------------------------
        */

        $designation = strtolower(trim((string) (
            $user->getAttribute('user_designation')
            ?? ''
        )));

        $designation = preg_replace(
            '/[\s_]+/',
            '-',
            $designation
        ) ?? $designation;

        /*
        |--------------------------------------------------------------------------
        | 5. Allowed backend designations
        |--------------------------------------------------------------------------
        */

        $allowedDesignations = [
            'administrator',
            'super-administrator',
            'admin',
            'backend-admin',
            'loan-manager',
            'credit',
            'operation',
        ];

        if (
            $designation !== ''
            && in_array(
                $designation,
                $allowedDesignations,
                true
            )
        ) {
            return true;
        }

        return false;
    });
}

    private function configureRateLimiters(): void
    {
        RateLimiter::for(
            'esign-backend',
            fn (Request $request): Limit =>
                Limit::perMinute(60)->by(
                    'backend:'.(
                        $request->user()?->getAuthIdentifier()
                        ?? $request->ip()
                    )
                )
        );

        RateLimiter::for(
            'esign-customer',
            fn (Request $request): Limit =>
                Limit::perMinute(20)->by(
                    'customer:'.(
                        $request->user()?->getAuthIdentifier()
                        ?? $request->ip()
                    )
                )
        );

        RateLimiter::for(
            'esign-webhook',
            fn (Request $request): Limit =>
                Limit::perMinute(120)->by(
                    'webhook:'.$request->ip()
                )
        );
    }
}