<?php

namespace Modules\Crif\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;

class CrifReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'crif_reports';

    protected $fillable = [
        'uuid',
        'user_id',

        'lead_uuid',
        'lead_id',

        'applicant_id',
        'applicant_sno',
        'applicant_type',

        'full_name',
        'pan_card_number',
        'mobile_number',
        'date_of_birth',

        'consent',
        'consent_message',
        'consent_given_at',

        'request_payload',
        'response_payload',

        'reference_key',
        'vendor_request_id',
        'idempotency_key',

        'http_status_code',
        'verification_status',
        'bureau_score',

        'error_code',
        'error_message',

        'requested_at',
        'completed_at',
    ];

    protected $hidden = [
        'request_payload',
        'response_payload',
    ];

    protected $casts = [
        'applicant_id' => 'integer',
        'applicant_sno' => 'integer',

        'date_of_birth' => 'date',

        'consent' => 'boolean',

        'request_payload' => 'array',
        'response_payload' => 'array',

        'bureau_score' => 'integer',
        'http_status_code' => 'integer',

        'consent_given_at' => 'datetime',
        'requested_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CrifReport $report): void {
            if (empty($report->uuid)) {
                $report->uuid = (string) Str::uuid();
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(
            BLApplicant::class,
            'applicant_id',
            'id'
        );
    }

    public function applicantDetail(): BelongsTo
    {
        return $this->belongsTo(
            BLApplicantDetails::class,
            'applicant_id',
            'applicant_id'
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'user_id',
            'id'
        );
    }
}