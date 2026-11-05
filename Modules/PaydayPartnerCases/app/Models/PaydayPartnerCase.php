<?php

namespace Modules\PaydayPartnerCases\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
// use Modules\PaydayPartnerCases\Database\Factories\PaydayPartnerCaseFactory;

class PaydayPartnerCase extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'payday_partner_cases';

    protected $fillable = [
        // Case Identification
        'case_number',
        'partner_case_id',
        'partner_id',
        'user_id',

        // Applicant / Loan Info
        'applicant_name',
        'applicant_mobile',
        'applicant_email',
        'loan_amount',
        'loan_tenure_days',

        // Local Disbursement Details
        'disbursement_amount',
        'bank_name',
        'utr_number',
        'disbursement_date',
        'disbursement_remarks',

        // API Request Details
        'api_endpoint',
        'api_method',
        'api_request_headers',
        'api_request_payload',

        // API Response Details
        'api_http_status_code',
        'api_response_status',
        'api_response_code',
        'api_response_message',
        'api_response_body',
        'api_response_time_ms',

        // Case / Application Status
        'case_status',
        'approval_status',
        'disbursement_status',
        'api_call_status',

        // Partner Sync / Pull / ACK
        'partner_sync_status',
        'partner_sync_marked_at',
        'partner_sync_marked_by',
        'partner_pulled_at',
        'partner_pull_count',
        'partner_last_pull_request_id',
        'partner_ack_status',
        'partner_ack_at',
        'partner_ack_reference_id',
        'partner_ack_payload',
        'partner_sync_error',
        'partner_sync_error_at',

        // Retry / Error Handling
        'retry_count',
        'last_retry_at',
        'error_description',

        // Webhook / Callback
        'webhook_payload',
        'webhook_received_at',
        'webhook_status',

        // Audit
        'created_by',
        'updated_by',
        'approved_by',
        'rejected_by',
        'disbursed_by',
        'approved_at',
        'rejected_at',
        'disbursed_at',
    ];

    protected $casts = [
        // JSON columns
        'api_request_headers'   => 'array',
        'api_request_payload'   => 'array',
        'api_response_body'     => 'array',
        'webhook_payload'       => 'array',
        'partner_ack_payload'   => 'array',

        // Decimals
        'loan_amount'           => 'decimal:2',
        'disbursement_amount'   => 'decimal:2',
        'api_response_time_ms'  => 'decimal:2',

        // Integers
        'loan_tenure_days'      => 'integer',
        'api_http_status_code'  => 'integer',
        'retry_count'           => 'integer',
        'partner_id'            => 'integer',
        'user_id'               => 'integer',
        'approved_by'           => 'integer',
        'rejected_by'           => 'integer',
        'disbursed_by'          => 'integer',
        'partner_sync_marked_by'=> 'integer',
        'partner_pull_count'    => 'integer',

        // Dates / Timestamps
        'disbursement_date'     => 'date',
        'last_retry_at'         => 'datetime',
        'webhook_received_at'   => 'datetime',
        'approved_at'           => 'datetime',
        'rejected_at'           => 'datetime',
        'disbursed_at'          => 'datetime',
        'partner_sync_marked_at'=> 'datetime',
        'partner_pulled_at'     => 'datetime',
        'partner_ack_at'        => 'datetime',
        'partner_sync_error_at' => 'datetime',
    ];

    protected $appends = [
        'is_disbursed',
        'can_send_to_partner',
        'is_ready_for_partner',
        'is_partner_pulled',
        'is_partner_acknowledged',
    ];

    public const CASE_STATUSES = [
        'initiated',
        'submitted',
        'under_review',
        'approved',
        'rejected',
        'disbursed',
        'cancelled',
        'error',
    ];

    public const APPROVAL_STATUSES = [
        'pending',
        'approved',
        'rejected',
    ];

    public const DISBURSEMENT_STATUSES = [
        'pending',
        'approved',
        'rejected',
        'disbursed',
    ];

    public const API_CALL_STATUSES = [
        'pending',
        'success',
        'failed',
        'timeout',
        'retry',
    ];

    public const WEBHOOK_STATUSES = [
        'awaited',
        'received',
        'processed',
        'failed',
    ];

    public const PARTNER_SYNC_PENDING = 'pending';
    public const PARTNER_SYNC_READY = 'ready';
    public const PARTNER_SYNC_PULLED = 'pulled';
    public const PARTNER_SYNC_ACKNOWLEDGED = 'acknowledged';
    public const PARTNER_SYNC_FAILED = 'failed';

    public const PARTNER_ACK_PENDING = 'pending';
    public const PARTNER_ACK_ACKNOWLEDGED = 'acknowledged';
    public const PARTNER_ACK_FAILED = 'failed';

    public const PARTNER_SYNC_STATUSES = [
        self::PARTNER_SYNC_PENDING,
        self::PARTNER_SYNC_READY,
        self::PARTNER_SYNC_PULLED,
        self::PARTNER_SYNC_ACKNOWLEDGED,
        self::PARTNER_SYNC_FAILED,
    ];

    public const PARTNER_ACK_STATUSES = [
        self::PARTNER_ACK_PENDING,
        self::PARTNER_ACK_ACKNOWLEDGED,
        self::PARTNER_ACK_FAILED,
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(
            \Modules\PaydayLoanPartner\Models\PaydayLoanPartner::class,
            'partner_id'
        );
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('case_status', $status);
    }

    public function scopeByApprovalStatus($query, string $status)
    {
        return $query->where('approval_status', $status);
    }

    public function scopeByDisbursementStatus($query, string $status)
    {
        return $query->where('disbursement_status', $status);
    }

    public function scopeByApiCallStatus($query, string $status)
    {
        return $query->where('api_call_status', $status);
    }

    public function scopeByPartner($query, int $partnerId)
    {
        return $query->where('partner_id', $partnerId);
    }

    public function scopeRetryable($query)
    {
        return $query->whereIn('api_call_status', ['failed', 'timeout'])
            ->orWhere('case_status', 'error');
    }

    public function scopePartnerSyncPending($query)
    {
        return $query->where('partner_sync_status', self::PARTNER_SYNC_PENDING);
    }

    public function scopePartnerSyncReady($query)
    {
        return $query->where('partner_sync_status', self::PARTNER_SYNC_READY);
    }

    public function scopePartnerSyncPulled($query)
    {
        return $query->where('partner_sync_status', self::PARTNER_SYNC_PULLED);
    }

    public function scopePartnerAcknowledged($query)
    {
        return $query
            ->where('partner_sync_status', self::PARTNER_SYNC_ACKNOWLEDGED)
            ->where('partner_ack_status', self::PARTNER_ACK_ACKNOWLEDGED);
    }

    public function scopeReadyForPartnerPull($query)
    {
        return $query
            ->where('case_status', 'disbursed')
            ->where('disbursement_status', 'disbursed')
            ->whereIn('partner_sync_status', [
                self::PARTNER_SYNC_READY,
                self::PARTNER_SYNC_PULLED,
            ])
            ->where('partner_ack_status', '!=', self::PARTNER_ACK_ACKNOWLEDGED);
    }

    public function getIsSuccessAttribute(): bool
    {
        return $this->api_call_status === 'success';
    }

    public function getIsRetryableAttribute(): bool
    {
        return in_array($this->api_call_status, ['failed', 'timeout', 'error'], true);
    }

    public function getIsDisbursedAttribute(): bool
    {
        return $this->case_status === 'disbursed'
            && $this->disbursement_status === 'disbursed';
    }

    public function canBeSentToPartner(): bool
    {
        return $this->case_status === 'disbursed'
            && $this->disbursement_status === 'disbursed'
            && in_array($this->partner_sync_status, [
                self::PARTNER_SYNC_PENDING,
                self::PARTNER_SYNC_FAILED,
                null,
                '',
            ], true)
            && $this->partner_ack_status !== self::PARTNER_ACK_ACKNOWLEDGED;
    }

    public function getCanSendToPartnerAttribute(): bool
    {
        return $this->canBeSentToPartner();
    }

    public function getIsReadyForPartnerAttribute(): bool
    {
        return $this->partner_sync_status === self::PARTNER_SYNC_READY;
    }

    public function getIsPartnerPulledAttribute(): bool
    {
        return $this->partner_sync_status === self::PARTNER_SYNC_PULLED;
    }

    public function getIsPartnerAcknowledgedAttribute(): bool
    {
        return $this->partner_sync_status === self::PARTNER_SYNC_ACKNOWLEDGED
            && $this->partner_ack_status === self::PARTNER_ACK_ACKNOWLEDGED;
    }
  
 
    // public function user(): BelongsTo
    // {
    //     return $this->belongsTo(\App\Models\User::class, 'user_id');
    // 
    }