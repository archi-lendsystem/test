<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductAccessMiddleware
{
    public function handle(
        Request $request,
        Closure $next,
        string $productCode
    ): Response {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'errors' => null,
            ], 401);
        }

        // Preserve the existing super-admin bypass.
        if ($user->role === 'super_admin') {
            return $next($request);
        }

        $productCode = strtoupper(trim($productCode));

        $hasAccess = $user->products()
            ->where('products.code', $productCode)
            ->where('products.status', true)
            ->exists();

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Product inactive or access denied.',
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}