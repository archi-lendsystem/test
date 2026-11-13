<?php

namespace Modules\PaydayLoanPartner\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PartnerPullAuthMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $partnerCode = trim((string) $request->header('X-Partner-Code'));
        $clientId = trim((string) $request->header('X-Client-Id'));
        $clientSecret = trim((string) $request->header('X-Client-Secret'));

        if ($partnerCode === '' || $clientId === '' || $clientSecret === '') {
            return $this->unauthorized(
                message: 'Partner authentication headers are required.',
                status: 'missing_partner_credentials'
            );
        }

        try {
            $partner = PaydayLoanPartner::whereRaw('UPPER(payday_partner_code) = ?', [
                    strtoupper($partnerCode),
                ])
                ->where('pull_client_id', $clientId)
                ->first();

            if (!$partner) {
                return $this->unauthorized(
                    message: 'Invalid partner credentials.',
                    status: 'invalid_partner_credentials'
                );
            }

            if (!$partner->canUsePullApi()) {
                $partner->markPullAuthenticationFailure();

                return response()->json([
                    'success' => false,
                    'status_code' => 403,
                    'status' => 'partner_credentials_disabled',
                    'message' => 'Partner credentials are disabled or partner is inactive.',
                    'timestamp' => now()->format('Y-m-d H:i:s'),
                ], 403);
            }

            if (!Hash::check($clientSecret, (string) $partner->pull_client_secret_hash)) {
                $partner->markPullAuthenticationFailure();

                return $this->unauthorized(
                    message: 'Invalid partner credentials.',
                    status: 'invalid_partner_credentials'
                );
            }

            $partner->markPullAuthenticationSuccess();

            $request->attributes->set('payday_partner', $partner);

            return $next($request);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'status_code' => 500,
                'status' => 'partner_auth_error',
                'message' => 'Partner authentication failed.',
                'timestamp' => now()->format('Y-m-d H:i:s'),
            ], 500);
        }
    }

    private function unauthorized(string $message, string $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'status_code' => 401,
            'status' => $status,
            'message' => $message,
            'timestamp' => now()->format('Y-m-d H:i:s'),
        ], 401);
    }
}