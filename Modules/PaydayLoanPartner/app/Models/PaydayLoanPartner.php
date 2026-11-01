<?php

namespace Modules\PaydayLoanPartner\Models;

use Modules\UserManagement\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaydayLoanPartner extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payday_loan_partners';

    protected $fillable = [
        'uuid',
        'payday_partner_name',
        'payday_partner_code',
        'description',
        'payday_partner_id',
        'contact_email',
        'contact_phone',

      
        'api_url',
        'client_id',
        'client_secret',
        'access_token',
        'token_expires_at',
        'extra_config',

        'is_active',

        // Incoming Pull API credentials: Partner -> Sabrimala
        'pull_client_id',
        'pull_client_secret_hash',
        'pull_credentials_enabled',
        'pull_credentials_generated_at',
        'pull_credentials_rotated_at',
        'pull_last_authenticated_at',
        'pull_last_failed_at',
        'pull_failed_attempts',

        'created_by',
    ];

    protected $casts = [
        'extra_config'                  => 'array',
        'is_active'                     => 'boolean',
        'token_expires_at'              => 'datetime',

        'pull_credentials_enabled'      => 'boolean',
        'pull_credentials_generated_at' => 'datetime',
        'pull_credentials_rotated_at'   => 'datetime',
        'pull_last_authenticated_at'    => 'datetime',
        'pull_last_failed_at'           => 'datetime',
        'pull_failed_attempts'          => 'integer',
    ];

    protected $hidden = [
        'client_secret',
        'access_token',
        'pull_client_secret_hash',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasValidPullCredentials(): bool
    {
        return !empty($this->pull_client_id)
            && !empty($this->pull_client_secret_hash)
            && $this->pull_credentials_enabled === true;
    }

    public function canUsePullApi(): bool
    {
        return $this->is_active === true
            && $this->hasValidPullCredentials();
    }

    public function markPullAuthenticationSuccess(): void
    {
        $this->forceFill([
            'pull_last_authenticated_at' => now(),
            'pull_failed_attempts' => 0,
        ])->save();
    }

    public function markPullAuthenticationFailure(): void
    {
        $this->forceFill([
            'pull_last_failed_at' => now(),
            'pull_failed_attempts' => ((int) $this->pull_failed_attempts) + 1,
        ])->save();
    }
}