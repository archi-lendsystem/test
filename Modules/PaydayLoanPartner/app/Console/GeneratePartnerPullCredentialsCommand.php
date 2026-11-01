<?php

namespace Modules\PaydayLoanPartner\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\PaydayLoanPartner\Models\PaydayLoanPartner;
use Throwable;

class GeneratePartnerPullCredentialsCommand extends Command
{
  
    protected $signature = 'payday:generate-partner-credentials
                            {partner_code : Partner code, example PR0001}
                            {--rotate : Rotate existing credentials}
                            {--disable : Disable existing pull credentials}';

    protected $description = 'Generate or rotate secure pull API credentials for a payday loan partner.';

    public function handle(): int
    {
        $partnerCode = strtoupper(trim((string) $this->argument('partner_code')));

        if ($partnerCode === '') {
            $this->error('Partner code is required.');
            return self::FAILURE;
        }

        try {
            $partner = PaydayLoanPartner::whereRaw('UPPER(payday_partner_code) = ?', [$partnerCode])
                ->first();

            if (!$partner) {
                $this->error("Partner not found with code: {$partnerCode}");
                return self::FAILURE;
            }

            if ($this->option('disable')) {
                $partner->forceFill([
                    'pull_credentials_enabled' => false,
                    'pull_last_failed_at' => null,
                    'pull_failed_attempts' => 0,
                ])->save();

                $this->info('Partner pull credentials disabled successfully.');
                $this->line("Partner Name: {$partner->payday_partner_name}");
                $this->line("Partner Code: {$partner->payday_partner_code}");

                return self::SUCCESS;
            }

            $hasExistingCredentials = !empty($partner->pull_client_id)
                || !empty($partner->pull_client_secret_hash);

            if ($hasExistingCredentials && !$this->option('rotate')) {
                $this->warn('Pull credentials already exist for this partner.');
                $this->line('Use --rotate if you want to generate new credentials.');
                $this->line('');
                $this->line("Existing Client ID: {$partner->pull_client_id}");

                return self::FAILURE;
            }

            $cleanPartnerCode = preg_replace('/[^A-Z0-9]/', '', strtoupper($partner->payday_partner_code));

            $clientId = 'pp_' . $cleanPartnerCode . '_' . strtolower(Str::random(16));
            $clientSecret = Str::random(64);

            $now = now();

            $partner->forceFill([
                'pull_client_id' => $clientId,
                'pull_client_secret_hash' => Hash::make($clientSecret),
                'pull_credentials_enabled' => true,
                'pull_credentials_generated_at' => $partner->pull_credentials_generated_at ?: $now,
                'pull_credentials_rotated_at' => $hasExistingCredentials ? $now : null,
                'pull_last_authenticated_at' => null,
                'pull_last_failed_at' => null,
                'pull_failed_attempts' => 0,
            ])->save();

            $this->newLine();
            $this->info($hasExistingCredentials ? 'Partner pull credentials rotated successfully.' : 'Partner pull credentials generated successfully.');
            $this->newLine();

            $this->line('IMPORTANT: Copy the Client Secret now. It will not be visible again.');
            $this->newLine();

            $this->table(
                ['Field', 'Value'],
                [
                    ['Partner Name', $partner->payday_partner_name],
                    ['Partner Code', $partner->payday_partner_code],
                    ['Environment', app()->environment()],
                  
                    ['Base URL', rtrim((string) config('app.url'), '/') . '/api/v1'],
                    ['Client ID', $clientId],
                    ['Client Secret', $clientSecret],
                    ['Pull Endpoint', 'GET /partner/payday/disbursements'],
                    ['ACK Endpoint', 'POST /partner/payday/disbursements/ack'],
                ]
            );

            $this->newLine();
            $this->line('Required Headers:');
            $this->line('X-Partner-Code: ' . $partner->payday_partner_code);
            $this->line('X-Client-Id: ' . $clientId);
            $this->line('X-Client-Secret: ' . $clientSecret);
            $this->line('Accept: application/json');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed to generate partner pull credentials.');
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
