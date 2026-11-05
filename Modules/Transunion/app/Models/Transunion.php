<?php

namespace Modules\Transunion\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;

class Transunion extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'transunions';

    protected $fillable = [
        'uuid',

        'lead_uuid',
        'lead_id',
        'applicant_id',
        'applicant_sno',
        'applicant_type',
        'requested_by',
        'full_name',
        'mobile_number',
        'pan_card_number',
        'date_of_birth',
        'reference_key',
        'request_id',
        'product_code',
        'idempotency_key',
        'status',
        'http_status',
        'bureau_score',

        'environment',
        'billable',
        'reconciliation_status',

        /*
         * Consent.
         */
        'consent',
        'consented_at',
        'request_payload',
        'response_json',

        /*
         * PDF.
         */
        'pdf_path',
        'pdf_generated_at',
        'error_message',
    ];

    protected $casts = [
        'applicant_id' => 'integer',
        'applicant_sno' => 'integer',
        'date_of_birth' => 'date',
        'request_payload' => 'array',
        'response_json' => 'array',

        'http_status' => 'integer',
        'bureau_score' => 'integer',
        'billable' => 'boolean',
        'consent' => 'boolean',
        'consented_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $hidden = [
        'request_payload',
    ];

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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'requested_by',
            'id'
        );
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
