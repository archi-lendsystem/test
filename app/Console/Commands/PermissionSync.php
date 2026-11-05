<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use App\Models\Permission;

class PermissionSync extends Command
{
    protected $signature = 'permission:sync';

    protected $description = 'Sync permissions from routes';

    public function handle(): void
    {
        $routes = Route::getRoutes();

        $permissions = [];

        foreach ($routes as $route) {

            $middlewares = $route->gatherMiddleware();

            foreach ($middlewares as $middleware) {

                if (str_starts_with($middleware, 'permission:')) {

                    $slug = str_replace(
                        'permission:',
                        '',
                        $middleware
                    );

                    $parts = explode('.', $slug);

                    $module = $parts[0] ?? 'general';

                    $name = ucwords(
                        str_replace(
                            ['.', '-'],
                            ' ',
                            $slug
                        )
                    );

                    $permissions[$slug] = [
                        'name'   => $name,
                        'slug'   => $slug,
                        'module' => $module,
                    ];
                }
            }
        }

        foreach ($permissions as $permission) {

            Permission::updateOrCreate(
                ['slug' => $permission['slug']],
                $permission
            );
        }

        $this->info(
            count($permissions) .
            ' permissions synced successfully.'
        );
    }
}