<?php

namespace Modules\ESign\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BackendAccessGuard
{
    public function assert(Request $request): void
    {
        if ($request->user() === null) {
            throw new AuthorizationException('Authentication is required.');
        }

        $ability = trim((string) config('esign.authorization.backend_ability', 'manage-esign'));

        if ($ability === '' || ! Gate::has($ability) || Gate::forUser($request->user())->denies($ability)) {
            throw new AuthorizationException('You are not authorized to manage eSign agreements.');
        }
    }
}
