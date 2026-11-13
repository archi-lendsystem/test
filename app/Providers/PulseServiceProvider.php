<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class PulseServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewPulse', function ($user = null) {

            // Allow only local environment
            if (app()->isLocal()) {
                return true;
            }

            // Allow only specific IPs in production
            return in_array(request()->ip(), [
                '127.0.0.1',
                '::1',
                'YOUR_PUBLIC_IP',
            ]);
        });
    }
}