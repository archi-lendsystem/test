<?php

namespace Modules\Experian\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;


class Experian extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'experians';

    protected $fillable = [
        'uuid',

        'lead_uuid',
        'lead_id',

        'applicant_id',
        'applicant_sno',
        'applicant_type',

        'requested_by',

        'applicant_name',
        'pan_card_number',
        'mobile_number',
        'date_of_birth',

        'status',
        'bureau_score',

        'reference_key',
        'request_id',
        'idempotency_key',

        'environment',
        'billable',
        'http_status',

        'request_payload',
        'response_payload',

        'pdf_path',

        'error_message',
    ];

    protected $casts = [
        'applicant_id' =>
            'integer',

        'applicant_sno' =>
            'integer',

        'date_of_birth' =>
            'date',

        'request_payload' =>
            'array',

        'response_payload' =>
            'array',

        'billable' =>
            'boolean',

        'bureau_score' =>
            'integer',

        'http_status' =>
            'integer',

        'created_at' =>
            'datetime',

        'updated_at' =>
            'datetime',
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
}