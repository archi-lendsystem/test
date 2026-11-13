<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Auth\Mail\OtpMail;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Auth\Transformers\AuthResource;
use Modules\UserManagement\Models\LoginOtpVerification;
use Modules\UserManagement\Models\User;

class AuthController extends Controller
{
    use ApiResponse;

    private const OTP_SEND_MAX_ATTEMPTS = 3;

    private const OTP_VERIFY_MAX_ATTEMPTS = 5;

    private const RATE_LIMIT_DECAY_SECONDS = 300;

    private const OTP_EXPIRY_MINUTES = 10;
    private const PASSWORD_LOGIN_MAX_ATTEMPTS = 5;


    private function logContext(Request $request, array $extra = []): array
    {
        return array_merge([
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'request_id' => Str::uuid()->toString(),
            'timestamp' => Carbon::now('UTC')->toIso8601String(),
        ], $extra);
    }


    private function checkRateLimit(string $key, int $maxAttempts, array $logContext, string $logEvent): ?JsonResponse
    {
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            Log::channel('audit')->warning($logEvent, array_merge($logContext, [
                'retry_after_seconds' => $seconds,
            ]));

            return $this->error(
                'Too many attempts. Please try again in ' . ceil($seconds / 60) . ' minute(s).',
                ['retry_after' => $seconds],
                429
            );
        }

        RateLimiter::hit($key, self::RATE_LIMIT_DECAY_SECONDS);

        return null;
    }

    public function loginWithPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => [
                'required',
                'email:rfc,dns',
                'max:254',
            ],
            'password' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        $email = strtolower(trim($validated['email']));

        $context = $this->logContext($request, [
            'email' => $email,
        ]);

        Log::channel('audit')->info(
            'PASSWORD_LOGIN_ATTEMPT',
            $context
        );

        $rateLimitKey = 'password-login:' . sha1(
            $request->ip() . '|' . $email
        );

        if (
            $response = $this->checkRateLimit(
                $rateLimitKey,
                self::PASSWORD_LOGIN_MAX_ATTEMPTS,
                $context,
                'PASSWORD_LOGIN_RATE_LIMIT'
            )
        ) {
            return $response;
        }

        /** @var User|null $user */
        $user = User::whereRaw(
            'LOWER(user_email) = ?',
            [$email]
        )->first();



        $stored   = (string) ($user?->user_password ?? '');
        $isHashed = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon');

        $passwordOk = false;

        if ($user instanceof User && $stored !== '') {
            $passwordOk = $isHashed
                ? \Illuminate\Support\Facades\Hash::check($validated['password'], $stored)
                : hash_equals($stored, (string) $validated['password']);
        }

        if (! $passwordOk) {

            Log::channel('audit')->warning(
                'PASSWORD_LOGIN_FAILED',
                array_merge($context, [
                    'reason' => 'invalid_credentials',
                    'user_id' => $user?->id,
                ])
            );

            return $this->error(
                'Invalid email address or password.',
                null,
                422
            );
        }

        if (! $user->is_active) {
            Log::channel('audit')->warning(
                'PASSWORD_LOGIN_FAILED',
                array_merge($context, [
                    'reason'  => 'user_inactive',
                    'user_id' => $user->id,
                ])
            );

            return $this->error(
                'Your account has been deactivated. Please contact support.',
                null,
                403
            );
        }

        RateLimiter::clear($rateLimitKey);

        $token = $user
            ->createToken('crednexus-auth-token')
            ->plainTextToken;

        Log::channel('audit')->info(
            'USER_PASSWORD_LOGIN_SUCCESS',
            array_merge($context, [
                'user_id' => $user->id,
            ])
        );

        return $this->success([
            'user' => new AuthResource($user->load([
                'userType',
                'roles',
                'permissions',
                'products',
                'vendors',
            ])),
            'token' => $token,
        ], 'Login successful.');
    }


    public function sendOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:254'],
        ]);

        $context = $this->logContext($request, ['email' => $validated['email']]);

        Log::channel('audit')->info('OTP_REQUEST_INITIATED', $context);

        $rateLimitKey = 'otp-send:' . sha1($request->ip() . '|' . $validated['email']);

        if ($response = $this->checkRateLimit($rateLimitKey, self::OTP_SEND_MAX_ATTEMPTS, $context, 'OTP_RATE_LIMIT_EXCEEDED')) {
            return $response;
        }

        /** @var User|null $user */
        $user = User::where('user_email', $validated['email'])->first();

        if (! $user instanceof User) {
            Log::channel('audit')->warning('OTP_REQUEST_FAILED', array_merge($context, [
                'reason' => 'user_not_found',
            ]));

            sleep(1);

            return $this->error(
                'No account found with this email address.',
                null,
                404
            );
        }
        if (! $user->is_active) {
            Log::channel('audit')->warning('OTP_REQUEST_FAILED', array_merge($context, [
                'reason'  => 'user_inactive',
                'user_id' => $user->id,
            ]));

            return $this->error(
                'Your account has been deactivated. Please contact support.',
                null,
                403
            );
        }


        $otp = "";

        try {
            DB::transaction(function () use ($user, $request, $context, &$otp) {
                LoginOtpVerification::where('user_id', $user->id)
                    ->where('used', false)
                    ->update(['used' => true]);

                $otp = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

                LoginOtpVerification::create([
                    'user_id'    => $user->id,
                    'otp'        => $otp,
                    'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRY_MINUTES),
                    'used'       => false,
                    'ip_address' => $request->ip(),
                ]);

                Log::channel('audit')->info('OTP_GENERATED', array_merge($context, [
                    'user_id' => $user->id,
                ]));
            });
        } catch (\Throwable $e) {
            Log::channel('audit')->error('OTP_DB_FAILED', array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            return $this->error('Something went wrong. Please try again later.', null, 500);
        }

        try {
            Mail::to($user->user_email)->send(new OtpMail($user, $otp));

            Log::channel('audit')->info('OTP_SENT', array_merge($context, [
                'user_id' => $user->id,
            ]));
        } catch (\Throwable $e) {
            Log::channel('audit')->error('OTP_EMAIL_FAILED', array_merge($context, [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]));

            return $this->error('Failed to send OTP. Please try again later.', null, 500);
        }

        $responseData = app()->environment('local') ? ['otp' => $otp] : null;

        return $this->success(
            $responseData,
            'OTP sent successfully. Please check your email.'
        );
    }


    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:254'],
            'otp'   => ['required', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
        ]);

        $context = $this->logContext($request, ['email' => $validated['email']]);

        Log::channel('audit')->info('OTP_VERIFY_ATTEMPT', $context);

        $rateLimitKey = 'otp-verify:' . sha1($request->ip() . '|' . $validated['email']);

        if ($response = $this->checkRateLimit($rateLimitKey, self::OTP_VERIFY_MAX_ATTEMPTS, $context, 'OTP_VERIFY_RATE_LIMIT')) {
            return $response;
        }

        /** @var User|null $user */
        $user = User::where('user_email', $validated['email'])->first();

        if (! $user instanceof User || ! $user->is_active) {
            Log::channel('audit')->warning('OTP_VERIFY_INVALID_USER', $context);

            return $this->error('Invalid or expired OTP.', null, 422);
        }

        $otpRecord = LoginOtpVerification::where('user_id', $user->id)
            ->valid()
            ->latest('created_at')
            ->first();

        if (! $otpRecord || ! hash_equals((string) $otpRecord->otp, (string) $validated['otp'])) {
            Log::channel('audit')->warning('OTP_INVALID', array_merge($context, [
                'user_id' => $user->id,
            ]));

            return $this->error('Invalid or expired OTP.', null, 422);
        }

        try {
            $otpRecord->update(['used' => true]);
        } catch (\Throwable $e) {
            Log::channel('audit')->error('OTP_MARK_USED_FAILED', array_merge($context, [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]));

            return $this->error('Something went wrong. Please try again later.', null, 500);
        }

        RateLimiter::clear($rateLimitKey);

        Log::channel('audit')->info('OTP_VERIFIED', array_merge($context, [
            'user_id' => $user->id,
        ]));

        $token = $user->createToken('crednexus-auth-token')->plainTextToken;

        Log::channel('audit')->info('USER_LOGIN_SUCCESS', array_merge($context, [
            'user_id' => $user->id,
        ]));

        return $this->success([
            'user' => new AuthResource($user->load([
                'userType',
                'roles',
                  'permissions',
                'products',
                'vendors',
            ])),
            'token' => $token,
        ], 'Login successful.');
    }


    public function logout(Request $request): JsonResponse
    {
        $context = $this->logContext($request, [
            'user_id' => $request->user()?->id,
        ]);

        try {
            $request->user()->currentAccessToken()->delete();
        } catch (\Throwable $e) {
            Log::channel('audit')->error('LOGOUT_FAILED', array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            return $this->error('Logout failed. Please try again.', null, 500);
        }

        Log::channel('audit')->info('USER_LOGOUT', $context);

        return $this->success(null, 'Logged out successfully.');
    }
}
