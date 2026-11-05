<?php

namespace Modules\DltSms\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class MishtelSmsService
{
    /**
     * Mishtel API base URL.
     */
    private string $baseUrl;

    /**
     * Authentication mode.
     *
     * Supported:
     * - api_key
     * - credentials
     */
    private string $authMode;

    /**
     * Mishtel API key.
     */
    private ?string $apiKey;

    /**
     * Mishtel username.
     */
    private ?string $username;

    /**
     * Mishtel password.
     */
    private ?string $password;

    /**
     * HTTP request timeout in seconds.
     */
    private int $timeout;

    /**
     * HTTP connection timeout in seconds.
     */
    private int $connectTimeout;

    /**
     * Create Mishtel SMS service instance.
     */
    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('dltsms.mishtel.base_url'),
            '/'
        );

        $this->authMode = strtolower(
            trim(
                (string) config(
                    'dltsms.mishtel.auth_mode',
                    'credentials'
                )
            )
        );

        $this->apiKey = $this->nullableString(
            config('dltsms.mishtel.api_key')
        );

        $this->username = $this->nullableString(
            config('dltsms.mishtel.username')
        );

        $this->password = $this->nullableString(
            config('dltsms.mishtel.password')
        );

        $this->timeout = (int) config(
            'dltsms.mishtel.timeout',
            15
        );

        $this->connectTimeout = (int) config(
            'dltsms.mishtel.connect_timeout',
            5
        );
    }

    /**
     * Get available SMS balance from Mishtel.
     *
     * @throws RuntimeException
     */
    public function getBalance(): array
    {
        $this->validateProviderConfiguration();

        try {
            $response = Http::acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get(
                    $this->baseUrl . '/api/mt/GetBalance',
                    $this->balanceAuthenticationParameters()
                );

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Mishtel balance API returned HTTP status '
                    . $response->status()
                    . '.'
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'Invalid response received from Mishtel balance API.'
                );
            }

            $this->validateProviderResponse(
                $data,
                'Mishtel balance API'
            );

            return $data;
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to Mishtel SMS gateway.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Unexpected error while communicating with Mishtel SMS gateway.',
                0,
                $exception
            );
        }
    }

    /**
     * Send a DLT-compliant SMS through Mishtel.
     *
     * @throws RuntimeException
     */
   public function sendSms(
    string $mobileNumber,
    string $message,
        ?string $dltTemplateId = null
    ): array {
        $this->validateProviderConfiguration();
    $this->validateSmsConfiguration();

    $dltTemplateId = $this->resolveDltTemplateId(
        $dltTemplateId
    );

    $mobileNumber = $this->normalizeMobileNumber(
        $mobileNumber
    );

        $message = trim($message);

        if ($message === '') {
            throw new RuntimeException(
                'SMS message cannot be empty.'
            );
        }

        $params = array_merge(
            $this->smsAuthenticationParameters(),
            [
                'senderid' => (string) config(
                    'dltsms.mishtel.sender_id'
                ),

                'channel' => (string) config(
                    'dltsms.mishtel.channel',
                    'Trans'
                ),

                'DCS' => (int) config(
                    'dltsms.mishtel.dcs',
                    0
                ),

                'flashsms' => (int) config(
                    'dltsms.mishtel.flash_sms',
                    0
                ),

                'number' => $mobileNumber,

                'text' => $message,

                'route' => (string) config(
                    'dltsms.mishtel.route_id'
                ),

                'Peid' => (string) config(
                    'dltsms.mishtel.pe_id'
                ),

                // 'DLTTemplateId' => (string) config(
                //     'dltsms.mishtel.dlt_template_id'
                // ),

                'DLTTemplateId' => $dltTemplateId,
            ]
        );

        $telemarketerId = $this->nullableString(
            config('dltsms.mishtel.telemarketer_id')
        );

        /*
         * Telemarketer ID is optional.
         * Do not send the parameter when it is not configured.
         */
        if ($telemarketerId !== null) {
            $params['telemarketerid'] = $telemarketerId;
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get(
                    $this->baseUrl . '/api/mt/SendSMS',
                    $params
                );

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Mishtel SendSMS API returned HTTP status '
                    . $response->status()
                    . '.'
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'Invalid response received from Mishtel SendSMS API.'
                );
            }

            $this->validateProviderResponse(
                $data,
                'Mishtel SendSMS API'
            );

            return $data;
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to Mishtel SMS gateway.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Unexpected error while sending SMS through Mishtel.',
                0,
                $exception
            );
        }
    }

    /**
     * Get delivery status for a previously submitted SMS job.
     *
     * Mishtel documentation:
     *
     * /api/mt/GetDelivery
     *
     * @throws RuntimeException
     */
    public function getDelivery(
        string|int $jobId
    ): array {
        $this->validateProviderConfiguration();

        $jobId = trim(
            (string) $jobId
        );

        if ($jobId === '') {
            throw new RuntimeException(
                'Mishtel Job ID is required.'
            );
        }

        if (! preg_match('/^\d+$/', $jobId)) {
            throw new RuntimeException(
                'Mishtel Job ID must be numeric.'
            );
        }

        $params = array_merge(
            $this->deliveryAuthenticationParameters(),
            [
                'jobid' => $jobId,
            ]
        );

        try {
            $response = Http::acceptJson()
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->get(
                    $this->baseUrl . '/api/mt/GetDelivery',
                    $params
                );

            if (! $response->successful()) {
                throw new RuntimeException(
                    'Mishtel GetDelivery API returned HTTP status '
                    . $response->status()
                    . '.'
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException(
                    'Invalid response received from Mishtel GetDelivery API.'
                );
            }

            $this->validateProviderResponse(
                $data,
                'Mishtel GetDelivery API'
            );

            return $data;
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'Unable to connect to Mishtel SMS gateway.',
                0,
                $exception
            );
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException) {
                throw $exception;
            }

            throw new RuntimeException(
                'Unexpected error while checking Mishtel SMS delivery status.',
                0,
                $exception
            );
        }
    }

    /**
     * Authentication parameters required by GetBalance API.
     *
     * Mishtel documentation uses:
     *
     * User
     * Password
     */
    private function balanceAuthenticationParameters(): array
    {
        if ($this->authMode === 'api_key') {
            return [
                'APIKey' => $this->apiKey,
            ];
        }

        return [
            'User' => $this->username,
            'Password' => $this->password,
        ];
    }

    /**
     * Authentication parameters required by SendSMS API.
     *
     * Mishtel documentation uses:
     *
     * user
     * password
     */
    private function smsAuthenticationParameters(): array
    {
        if ($this->authMode === 'api_key') {
            return [
                'APIKey' => $this->apiKey,
            ];
        }

        return [
            'user' => $this->username,
            'password' => $this->password,
        ];
    }

    /**
     * Authentication parameters required by GetDelivery API.
     *
     * Mishtel documentation uses:
     *
     * user
     * password
     */
    private function deliveryAuthenticationParameters(): array
    {
        if ($this->authMode === 'api_key') {
            return [
                'APIKey' => $this->apiKey,
            ];
        }

        return [
            'user' => $this->username,
            'password' => $this->password,
        ];
    }

    /**
     * Normalize an Indian mobile number to the format
     * expected by Mishtel.
     *
     * Accepted:
     *
     * 9876543210
     * 919876543210
     * +919876543210
     *
     * Output:
     *
     * 919876543210
     *
     * @throws RuntimeException
     */
    private function normalizeMobileNumber(
        string $mobileNumber
    ): string {
        $number = preg_replace(
            '/\D+/',
            '',
            trim($mobileNumber)
        );

        if (! is_string($number) || $number === '') {
            throw new RuntimeException(
                'Mobile number is required.'
            );
        }

        /*
         * Local Indian mobile number.
         */
        if (strlen($number) === 10) {
            $number = '91' . $number;
        }

        /*
         * Must contain:
         *
         * 91 + 10 digit Indian mobile number.
         */
        if (
            strlen($number) !== 12
            || ! str_starts_with($number, '91')
        ) {
            throw new RuntimeException(
                'Invalid Indian mobile number.'
            );
        }

        $subscriberNumber = substr(
            $number,
            2
        );

        if (
            ! preg_match(
                '/^[6-9]\d{9}$/',
                $subscriberNumber
            )
        ) {
            throw new RuntimeException(
                'Invalid Indian mobile number.'
            );
        }

        return $number;
    }

    /**
     * Validate common Mishtel provider configuration.
     *
     * @throws RuntimeException
     */
    private function validateProviderConfiguration(): void
    {
        if ($this->baseUrl === '') {
            throw new RuntimeException(
                'MISHTEL_BASE_URL is not configured.'
            );
        }

        if (
            ! in_array(
                $this->authMode,
                [
                    'api_key',
                    'credentials',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'MISHTEL_AUTH_MODE must be either api_key or credentials.'
            );
        }

        if (
            $this->authMode === 'api_key'
            && empty($this->apiKey)
        ) {
            throw new RuntimeException(
                'MISHTEL_API_KEY is not configured.'
            );
        }

        if (
            $this->authMode === 'credentials'
            && (
                empty($this->username)
                || empty($this->password)
            )
        ) {
            throw new RuntimeException(
                'MISHTEL_USERNAME and MISHTEL_PASSWORD are required.'
            );
        }

        if ($this->timeout <= 0) {
            throw new RuntimeException(
                'MISHTEL_TIMEOUT must be greater than zero.'
            );
        }

        if ($this->connectTimeout <= 0) {
            throw new RuntimeException(
                'MISHTEL_CONNECT_TIMEOUT must be greater than zero.'
            );
        }
    }

    /**
     * Validate configuration required specifically for sending
     * a DLT-compliant SMS.
     *
     * @throws RuntimeException
     */
    private function validateSmsConfiguration(): void
    {
        $requiredConfiguration = [
            'MISHTEL_SENDER_ID' => config(
                'dltsms.mishtel.sender_id'
            ),

            'MISHTEL_CHANNEL' => config(
                'dltsms.mishtel.channel'
            ),

            'MISHTEL_ROUTE_ID' => config(
                'dltsms.mishtel.route_id'
            ),

            'MISHTEL_PE_ID' => config(
                'dltsms.mishtel.pe_id'
            ),

            // 'MISHTEL_DLT_TEMPLATE_ID' => config(
            //     'dltsms.mishtel.dlt_template_id'
            // ),
        ];

        foreach (
            $requiredConfiguration as $key => $value
        ) {
            if (
                $value === null
                || trim((string) $value) === ''
            ) {
                throw new RuntimeException(
                    $key . ' is not configured.'
                );
            }
        }

        $senderId = trim(
            (string) config(
                'dltsms.mishtel.sender_id'
            )
        );

        if (strlen($senderId) !== 6) {
            throw new RuntimeException(
                'MISHTEL_SENDER_ID must contain exactly 6 characters.'
            );
        }

        $dcs = (int) config(
            'dltsms.mishtel.dcs',
            0
        );

        if (! in_array($dcs, [0, 8], true)) {
            throw new RuntimeException(
                'MISHTEL_DCS must be either 0 or 8.'
            );
        }

        $flashSms = (int) config(
            'dltsms.mishtel.flash_sms',
            0
        );

        if (! in_array($flashSms, [0, 1], true)) {
            throw new RuntimeException(
                'MISHTEL_FLASH_SMS must be either 0 or 1.'
            );
        }
    }

    /**
     * Validate Mishtel business-level API response.
     *
     * Provider uses:
     *
     * 000 = success for SendSMS
     * 0   = success for balance/delivery APIs
     *
     * @throws RuntimeException
     */
    private function validateProviderResponse(
        array $data,
        string $context
    ): void {
        $errorCode = (string) (
            $data['ErrorCode']
            ?? ''
        );

        if (
            $errorCode !== '0'
            && $errorCode !== '000'
        ) {
            throw new RuntimeException(
                sprintf(
                    '%s failed. ErrorCode: %s, ErrorMessage: %s',
                    $context,
                    $errorCode !== ''
                        ? $errorCode
                        : 'UNKNOWN',
                    (string) (
                        $data['ErrorMessage']
                        ?? 'Unknown provider error'
                    )
                )
            );
        }
    }

    /**
 * Resolve DLT template ID.
 *
 * Module specific template ID has priority.
 * Old global template remains fallback.
 */
private function resolveDltTemplateId(
    ?string $dltTemplateId
): string {

    $dltTemplateId = trim(
        (string) $dltTemplateId
    );

    if ($dltTemplateId !== '') {
        return $dltTemplateId;
    }


    $defaultTemplateId = trim(
        (string) config(
            'dltsms.mishtel.dlt_template_id'
        )
    );


    if ($defaultTemplateId === '') {
        throw new RuntimeException(
            'DLT template ID is not configured.'
        );
    }


    return $defaultTemplateId;
}

    /**
     * Convert nullable configuration values into a clean
     * nullable string.
     */
    private function nullableString(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim(
            (string) $value
        );

        return $value !== ''
            ? $value
            : null;
    }
}