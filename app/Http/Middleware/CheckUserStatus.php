<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && $user->is_active != 1) {

            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}