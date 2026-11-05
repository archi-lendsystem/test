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
use Modules\DltSms\Services\DltTemplateService;
use Modules\DltSms\Services\MishtelSmsService;

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


    private function checkLoginChannel(string $requiredChannel): ?JsonResponse
    {
        $activeChannel = config('auth.login.channel');

        if (! in_array($activeChannel, ['email', 'dlt', 'password'], true)) {
            return $this->error(
                'Login configuration is invalid. Please contact your administrator.',
                null,
                503
            );
        }

        if ($activeChannel !== $requiredChannel) {
            return $this->error(
                'This login method is currently disabled.',
                null,
                403
            );
        }

        return null;
    }

    public function loginConfig(): JsonResponse
    {
        $channel = config('auth.login.channel');

        if (! in_array($channel, ['email', 'dlt', 'password'], true)) {
            return $this->error(
                'Login configuration is invalid. Please contact your administrator.',
                null,
                503
            );
        }

        $response = $this->success([
            'channel' => $channel,
            'identifier_type' => $channel === 'dlt' ? 'mobile' : 'email',
            'requires_otp' => $channel !== 'password',
            'otp_length' => match ($channel) {
                'email' => 4,
                'dlt' => 6,
                default => null,
            },
        ], 'Login configuration fetched successfully.');

        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function loginWithPassword(Request $request): JsonResponse
    {
        if ($response = $this->checkLoginChannel('password')) {
            return $response;
        }

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
                'products',
                'vendors',
            ])),
            'token' => $token,
        ], 'Login successful.');
    }


    public function sendOtp(Request $request): JsonResponse
    {
        if ($response = $this->checkLoginChannel('email')) {
            return $response;
        }

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
                    ->where('channel', 'email')
                    ->where('used', false)
                    ->update(['used' => true]);

                $otp = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

                LoginOtpVerification::create([
                    'user_id'    => $user->id,
                    'otp'        => $otp,
                    'channel'    => 'email',
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
        if ($response = $this->checkLoginChannel('email')) {
            return $response;
        }

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
            ->where('channel', 'email')
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
                'products',
                'vendors',
            ])),
            'token' => $token,
        ], 'Login successful.');
    }


    public function sendDltOtp(Request $request): JsonResponse
    {
        if ($response = $this->checkLoginChannel('dlt')) {
            return $response;
        }

        $validated = $request->validate([
            'mobile' => [
                'required',
                'string',
                'max:13',
                'regex:/^(?:\+?91)?[6-9][0-9]{9}$/D',
            ],
        ]);

        $mobileVariants = $this->dltMobileVariants($validated['mobile']);
        $mobile = $mobileVariants[0];

        $context = $this->logContext($request, [
            'channel' => 'dlt',
        ]);

        Log::channel('audit')->info('DLT_OTP_REQUEST_INITIATED', $context);

        // Limit requests by IP and by normalized mobile separately.
        $ipRateLimitKey = 'dlt-otp-send-ip:' . sha1($request->ip());

        if (
            $response = $this->checkRateLimit(
                $ipRateLimitKey,
                self::OTP_SEND_MAX_ATTEMPTS,
                $context,
                'DLT_OTP_SEND_IP_RATE_LIMIT'
            )
        ) {
            return $response;
        }

        $mobileRateLimitKey = 'dlt-otp-send-mobile:' . sha1($mobile);

        if (
            $response = $this->checkRateLimit(
                $mobileRateLimitKey,
                self::OTP_SEND_MAX_ATTEMPTS,
                $context,
                'DLT_OTP_SEND_MOBILE_RATE_LIMIT'
            )
        ) {
            return $response;
        }

        if (config('auth.dlt.template') !== 'staff_login_otp') {
            return $this->error(
                'Staff SMS login is not configured. Please contact your administrator.',
                null,
                503
            );
        }

        $templateId = trim(
            (string) config('dltsms.templates.staff_login_otp.dlt_template_id')
        );

        if ($templateId === '' || ! ctype_digit($templateId)) {
            return $this->error(
                'Staff SMS template is not configured. Please contact your administrator.',
                null,
                503
            );
        }

        try {
            $templateService = app(DltTemplateService::class);

            $expiryMinutes = $templateService->getStaffLoginOtpExpiryMinutes();

            $otp = str_pad(
                (string) random_int(0, 999999),
                6,
                '0',
                STR_PAD_LEFT
            );

            $message = $templateService->renderStaffLoginOtp($otp);

            $sessionId = Str::uuid()->toString();

            $otpRecord = DB::transaction(function () use (
                $mobileVariants,
                $request,
                $otp,
                $sessionId,
                $expiryMinutes
            ) {
                $users = User::whereIn('user_mobile_number', $mobileVariants)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                // Never select an arbitrary account for a duplicate mobile.
                if ($users->count() !== 1 || ! $users->first()->is_active) {
                    return null;
                }

                $user = $users->first();

                LoginOtpVerification::where('user_id', $user->id)
                    ->where('channel', 'dlt')
                    ->where('used', false)
                    ->update(['used' => true]);

                return LoginOtpVerification::create([
                    'user_id'    => $user->id,
                    'otp'        => $otp,
                    'channel'    => 'dlt',
                    'expires_at' => Carbon::now('UTC')->addMinutes($expiryMinutes),

                    // Keep unusable until the gateway accepts the SMS.
                    'used'       => true,

                    'session_id' => $sessionId,
                    'ip_address' => $request->ip(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::channel('audit')->error('DLT_OTP_PREPARATION_FAILED', array_merge($context, [
                'exception_type' => get_class($e),
            ]));

            return $this->error(
                'Unable to prepare OTP. Please try again later.',
                null,
                500
            );
        }

        if (! $otpRecord instanceof LoginOtpVerification) {
            return $this->error(
                'Unable to send login OTP. Please contact your administrator.',
                null,
                422
            );
        }

        $context = array_merge($context, [
            'user_id' => $otpRecord->user_id,
        ]);

        try {
            // Send outside the transaction to avoid holding locks during HTTP I/O.
            app(MishtelSmsService::class)->sendSms(
                $mobile,
                $message,
                $templateId
            );
        } catch (\Throwable $e) {
            // The record remains used=true and cannot be verified.
            // Do not log raw provider exceptions containing credentials or URLs.
            Log::channel('audit')->error('DLT_OTP_SMS_FAILED', array_merge($context, [
                'exception_type' => get_class($e),
            ]));

            return $this->error(
                'Failed to send OTP. Please try again later.',
                null,
                503
            );
        }

        try {
            $activated = DB::transaction(function () use ($otpRecord, $mobileVariants) {
                $user = User::whereKey($otpRecord->user_id)
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $user instanceof User
                    || ! $user->is_active
                    || ! in_array($user->user_mobile_number, $mobileVariants, true)
                ) {
                    return false;
                }

                $latest = LoginOtpVerification::where('user_id', $user->id)
                    ->where('channel', 'dlt')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                // Do not reactivate an older request after a newer resend.
                if (
                    ! $latest
                    || $latest->id !== $otpRecord->id
                    || $latest->expires_at->lte(Carbon::now('UTC'))
                ) {
                    return false;
                }

                $latest->update(['used' => false]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::channel('audit')->error('DLT_OTP_ACTIVATION_FAILED', array_merge($context, [
                'exception_type' => get_class($e),
            ]));

            return $this->error(
                'Unable to complete OTP request. Please try again later.',
                null,
                500
            );
        }

        if (! $activated) {
            return $this->error(
                'This OTP request has expired or been replaced. Please request a new OTP.',
                null,
                422
            );
        }

        Log::channel('audit')->info('DLT_OTP_SENT', $context);

        return $this->success([
            'session_id' => $sessionId,
            'expires_in_seconds' => max(
                0,
                $otpRecord->expires_at->getTimestamp()
                    - Carbon::now('UTC')->getTimestamp()
            ),
        ], 'OTP sent successfully. Please check your SMS.');
    }


    public function verifyDltOtp(Request $request): JsonResponse
    {
        if ($response = $this->checkLoginChannel('dlt')) {
            return $response;
        }

        $validated = $request->validate([
            'mobile' => [
                'required',
                'string',
                'max:13',
                'regex:/^(?:\+?91)?[6-9][0-9]{9}$/D',
            ],
            'session_id' => [
                'required',
                'uuid',
            ],
            'otp' => [
                'required',
                'string',
                'size:6',
                'regex:/^[0-9]{6}$/D',
            ],
        ]);

        $mobileVariants = $this->dltMobileVariants($validated['mobile']);
        $mobile = $mobileVariants[0];

        $context = $this->logContext($request, [
            'channel' => 'dlt',
        ]);

        Log::channel('audit')->info('DLT_OTP_VERIFY_ATTEMPT', $context);

        $ipRateLimitKey = 'dlt-otp-verify-ip:' . sha1($request->ip());

        if (
            $response = $this->checkRateLimit(
                $ipRateLimitKey,
                self::OTP_VERIFY_MAX_ATTEMPTS,
                $context,
                'DLT_OTP_VERIFY_IP_RATE_LIMIT'
            )
        ) {
            return $response;
        }

        $mobileRateLimitKey = 'dlt-otp-verify-mobile:' . sha1($mobile);

        if (
            $response = $this->checkRateLimit(
                $mobileRateLimitKey,
                self::OTP_VERIFY_MAX_ATTEMPTS,
                $context,
                'DLT_OTP_VERIFY_MOBILE_RATE_LIMIT'
            )
        ) {
            return $response;
        }

        try {
            $result = DB::transaction(function () use ($mobileVariants, $validated) {
                // Same lock order as sending: user first, OTP record second.
                $users = User::whereIn('user_mobile_number', $mobileVariants)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($users->count() !== 1 || ! $users->first()->is_active) {
                    return null;
                }

                $user = $users->first();

                $otpRecord = LoginOtpVerification::where('user_id', $user->id)
                    ->where('channel', 'dlt')
                    ->where('session_id', $validated['session_id'])
                    ->valid()
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (
                    ! $otpRecord
                    || ! hash_equals(
                        (string) $otpRecord->otp,
                        (string) $validated['otp']
                    )
                ) {
                    return null;
                }

                $user->load([
                    'userType',
                    'roles',
                    'products',
                    'vendors',
                ]);

                $otpRecord->update(['used' => true]);

                $token = $user
                    ->createToken('crednexus-auth-token')
                    ->plainTextToken;

                return [
                    'user' => $user,
                    'token' => $token,
                ];
            });
        } catch (\Throwable $e) {
            Log::channel('audit')->error('DLT_OTP_VERIFY_FAILED', array_merge($context, [
                'exception_type' => get_class($e),
            ]));

            return $this->error(
                'Something went wrong. Please try again later.',
                null,
                500
            );
        }

        if ($result === null) {
            Log::channel('audit')->warning('DLT_OTP_INVALID', $context);

            return $this->error(
                'Invalid or expired OTP.',
                null,
                422
            );
        }

        RateLimiter::clear($mobileRateLimitKey);

        Log::channel('audit')->info('USER_DLT_LOGIN_SUCCESS', array_merge($context, [
            'user_id' => $result['user']->id,
        ]));

        return $this->success([
            'user' => new AuthResource($result['user']),
            'token' => $result['token'],
        ], 'Login successful.');
    }


    private function dltMobileVariants(string $mobile): array
    {
        // Called only after mobile validation.
        $mobile = ltrim($mobile, '+');

        if (strlen($mobile) === 10) {
            $mobile = '91' . $mobile;
        }

        return [
            $mobile,
            '+' . $mobile,
            substr($mobile, 2),
        ];
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






// namespace Modules\Auth\Http\Controllers;

// use App\Http\Controllers\Controller;
// use Modules\Auth\Mail\OtpMail;
// use App\Traits\ApiResponse;
// use Carbon\Carbon;
// use Illuminate\Http\JsonResponse;
// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\DB;
// use Illuminate\Support\Facades\Log;
// use Illuminate\Support\Facades\Mail;
// use Illuminate\Support\Facades\RateLimiter;
// use Illuminate\Support\Str;
// use Modules\Auth\Transformers\AuthResource;
// use Modules\UserManagement\Models\LoginOtpVerification;
// use Modules\UserManagement\Models\User;

// class AuthController extends Controller
// {
//     use ApiResponse;

//     private const OTP_SEND_MAX_ATTEMPTS = 3;

//     private const OTP_VERIFY_MAX_ATTEMPTS = 5;

//     private const RATE_LIMIT_DECAY_SECONDS = 300;

//     private const OTP_EXPIRY_MINUTES = 10;
//     private const PASSWORD_LOGIN_MAX_ATTEMPTS = 5;


//     private function logContext(Request $request, array $extra = []): array
//     {
//         return array_merge([
//             'ip' => $request->ip(),
//             'user_agent' => $request->userAgent(),
//             'request_id' => Str::uuid()->toString(),
//             'timestamp' => Carbon::now('UTC')->toIso8601String(),
//         ], $extra);
//     }


//     private function checkRateLimit(string $key, int $maxAttempts, array $logContext, string $logEvent): ?JsonResponse
//     {
//         if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
//             $seconds = RateLimiter::availableIn($key);

//             Log::channel('audit')->warning($logEvent, array_merge($logContext, [
//                 'retry_after_seconds' => $seconds,
//             ]));

//             return $this->error(
//                 'Too many attempts. Please try again in ' . ceil($seconds / 60) . ' minute(s).',
//                 ['retry_after' => $seconds],
//                 429
//             );
//         }

//         RateLimiter::hit($key, self::RATE_LIMIT_DECAY_SECONDS);

//         return null;
//     }

//     public function loginWithPassword(Request $request): JsonResponse
//     {
//         $validated = $request->validate([
//             'email' => [
//                 'required',
//                 'email:rfc,dns',
//                 'max:254',
//             ],
//             'password' => [
//                 'required',
//                 'string',
//                 'max:255',
//             ],
//         ]);

//         $email = strtolower(trim($validated['email']));

//         $context = $this->logContext($request, [
//             'email' => $email,
//         ]);

//         Log::channel('audit')->info(
//             'PASSWORD_LOGIN_ATTEMPT',
//             $context
//         );

//         $rateLimitKey = 'password-login:' . sha1(
//             $request->ip() . '|' . $email
//         );

//         if (
//             $response = $this->checkRateLimit(
//                 $rateLimitKey,
//                 self::PASSWORD_LOGIN_MAX_ATTEMPTS,
//                 $context,
//                 'PASSWORD_LOGIN_RATE_LIMIT'
//             )
//         ) {
//             return $response;
//         }

//         /** @var User|null $user */
//         $user = User::whereRaw(
//             'LOWER(user_email) = ?',
//             [$email]
//         )->first();



//         $stored   = (string) ($user?->user_password ?? '');
//         $isHashed = str_starts_with($stored, '$2y$') || str_starts_with($stored, '$argon');

//         $passwordOk = false;

//         if ($user instanceof User && $stored !== '') {
//             $passwordOk = $isHashed
//                 ? \Illuminate\Support\Facades\Hash::check($validated['password'], $stored)
//                 : hash_equals($stored, (string) $validated['password']);
//         }

//         if (! $passwordOk) {

//             Log::channel('audit')->warning(
//                 'PASSWORD_LOGIN_FAILED',
//                 array_merge($context, [
//                     'reason' => 'invalid_credentials',
//                     'user_id' => $user?->id,
//                 ])
//             );

//             return $this->error(
//                 'Invalid email address or password.',
//                 null,
//                 422
//             );
//         }

//         if (! $user->is_active) {
//             Log::channel('audit')->warning(
//                 'PASSWORD_LOGIN_FAILED',
//                 array_merge($context, [
//                     'reason'  => 'user_inactive',
//                     'user_id' => $user->id,
//                 ])
//             );

//             return $this->error(
//                 'Your account has been deactivated. Please contact support.',
//                 null,
//                 403
//             );
//         }

//         RateLimiter::clear($rateLimitKey);

//         $token = $user
//             ->createToken('crednexus-auth-token')
//             ->plainTextToken;

//         Log::channel('audit')->info(
//             'USER_PASSWORD_LOGIN_SUCCESS',
//             array_merge($context, [
//                 'user_id' => $user->id,
//             ])
//         );

//         return $this->success([
//             'user' => new AuthResource($user->load([
//                 'userType',
//                 'roles',
//                 'products',
//                 'vendors',
//             ])),
//             'token' => $token,
//         ], 'Login successful.');
//     }


//     public function sendOtp(Request $request): JsonResponse
//     {
//         $validated = $request->validate([
//             'email' => ['required', 'email:rfc,dns', 'max:254'],
//         ]);

//         $context = $this->logContext($request, ['email' => $validated['email']]);

//         Log::channel('audit')->info('OTP_REQUEST_INITIATED', $context);

//         $rateLimitKey = 'otp-send:' . sha1($request->ip() . '|' . $validated['email']);

//         if ($response = $this->checkRateLimit($rateLimitKey, self::OTP_SEND_MAX_ATTEMPTS, $context, 'OTP_RATE_LIMIT_EXCEEDED')) {
//             return $response;
//         }

//         /** @var User|null $user */
//         $user = User::where('user_email', $validated['email'])->first();

//         if (! $user instanceof User) {
//             Log::channel('audit')->warning('OTP_REQUEST_FAILED', array_merge($context, [
//                 'reason' => 'user_not_found',
//             ]));

//             sleep(1);

//             return $this->error(
//                 'No account found with this email address.',
//                 null,
//                 404
//             );
//         }
//         if (! $user->is_active) {
//             Log::channel('audit')->warning('OTP_REQUEST_FAILED', array_merge($context, [
//                 'reason'  => 'user_inactive',
//                 'user_id' => $user->id,
//             ]));

//             return $this->error(
//                 'Your account has been deactivated. Please contact support.',
//                 null,
//                 403
//             );
//         }


//         $otp = "";

//         try {
//             DB::transaction(function () use ($user, $request, $context, &$otp) {
//                 LoginOtpVerification::where('user_id', $user->id)
//                     ->where('used', false)
//                     ->update(['used' => true]);

//                 $otp = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

//                 LoginOtpVerification::create([
//                     'user_id'    => $user->id,
//                     'otp'        => $otp,
//                     'expires_at' => Carbon::now('UTC')->addMinutes(self::OTP_EXPIRY_MINUTES),
//                     'used'       => false,
//                     'ip_address' => $request->ip(),
//                 ]);

//                 Log::channel('audit')->info('OTP_GENERATED', array_merge($context, [
//                     'user_id' => $user->id,
//                 ]));
//             });
//         } catch (\Throwable $e) {
//             Log::channel('audit')->error('OTP_DB_FAILED', array_merge($context, [
//                 'error' => $e->getMessage(),
//             ]));

//             return $this->error('Something went wrong. Please try again later.', null, 500);
//         }

//         try {
//             Mail::to($user->user_email)->send(new OtpMail($user, $otp));

//             Log::channel('audit')->info('OTP_SENT', array_merge($context, [
//                 'user_id' => $user->id,
//             ]));
//         } catch (\Throwable $e) {
//             Log::channel('audit')->error('OTP_EMAIL_FAILED', array_merge($context, [
//                 'user_id' => $user->id,
//                 'error'   => $e->getMessage(),
//             ]));

//             return $this->error('Failed to send OTP. Please try again later.', null, 500);
//         }

//         $responseData = app()->environment('local') ? ['otp' => $otp] : null;

//         return $this->success(
//             $responseData,
//             'OTP sent successfully. Please check your email.'
//         );
//     }


//     public function verifyOtp(Request $request): JsonResponse
//     {
//         $validated = $request->validate([
//             'email' => ['required', 'email:rfc,dns', 'max:254'],
//             'otp'   => ['required', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
//         ]);

//         $context = $this->logContext($request, ['email' => $validated['email']]);

//         Log::channel('audit')->info('OTP_VERIFY_ATTEMPT', $context);

//         $rateLimitKey = 'otp-verify:' . sha1($request->ip() . '|' . $validated['email']);

//         if ($response = $this->checkRateLimit($rateLimitKey, self::OTP_VERIFY_MAX_ATTEMPTS, $context, 'OTP_VERIFY_RATE_LIMIT')) {
//             return $response;
//         }

//         /** @var User|null $user */
//         $user = User::where('user_email', $validated['email'])->first();

//         if (! $user instanceof User || ! $user->is_active) {
//             Log::channel('audit')->warning('OTP_VERIFY_INVALID_USER', $context);

//             return $this->error('Invalid or expired OTP.', null, 422);
//         }

//         $otpRecord = LoginOtpVerification::where('user_id', $user->id)
//             ->valid()
//             ->latest('created_at')
//             ->first();

//         if (! $otpRecord || ! hash_equals((string) $otpRecord->otp, (string) $validated['otp'])) {
//             Log::channel('audit')->warning('OTP_INVALID', array_merge($context, [
//                 'user_id' => $user->id,
//             ]));

//             return $this->error('Invalid or expired OTP.', null, 422);
//         }

//         try {
//             $otpRecord->update(['used' => true]);
//         } catch (\Throwable $e) {
//             Log::channel('audit')->error('OTP_MARK_USED_FAILED', array_merge($context, [
//                 'user_id' => $user->id,
//                 'error' => $e->getMessage(),
//             ]));

//             return $this->error('Something went wrong. Please try again later.', null, 500);
//         }

//         RateLimiter::clear($rateLimitKey);

//         Log::channel('audit')->info('OTP_VERIFIED', array_merge($context, [
//             'user_id' => $user->id,
//         ]));

//         $token = $user->createToken('crednexus-auth-token')->plainTextToken;

//         Log::channel('audit')->info('USER_LOGIN_SUCCESS', array_merge($context, [
//             'user_id' => $user->id,
//         ]));

//         return $this->success([
//             'user' => new AuthResource($user->load([
//                 'userType',
//                 'roles',
//                 'products',
//                 'vendors',
//             ])),
//             'token' => $token,
//         ], 'Login successful.');
//     }


//     public function logout(Request $request): JsonResponse
//     {
//         $context = $this->logContext($request, [
//             'user_id' => $request->user()?->id,
//         ]);

//         try {
//             $request->user()->currentAccessToken()->delete();
//         } catch (\Throwable $e) {
//             Log::channel('audit')->error('LOGOUT_FAILED', array_merge($context, [
//                 'error' => $e->getMessage(),
//             ]));

//             return $this->error('Logout failed. Please try again.', null, 500);
//         }

//         Log::channel('audit')->info('USER_LOGOUT', $context);

//         return $this->success(null, 'Logged out successfully.');
//     }
// }