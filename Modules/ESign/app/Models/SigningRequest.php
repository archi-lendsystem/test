<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SigningRequest extends Model
{
    use HasFactory;

    protected $table = 'esign_signing_requests';

    protected $fillable = [
        'uuid',
        'lead_uuid',
        'module_type',
        'module_id',

        'digio_request_id',
        'digio_doc_id',
        'callback',

        'document_type',

        'original_pdf_path',
        'original_pdf_checksum',

        'signed_pdf_path',
        'signed_pdf_checksum',

        'audit_trail_path',
        'storage_disk',

        'status',
        'provider_status',

        'signer_details',
        'signing_url',

        'error_message',
        'webhook_event_id',

        'attempt_count',

        'expires_at',
        'last_synced_at',
        'failed_at',

        'initiated_by',
        'completed_at',
    ];

    protected $casts = [
        'module_id' => 'integer',

        'signer_details' => 'array',

        'attempt_count' => 'integer',

        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'failed_at' => 'datetime',
        'completed_at' => 'datetime',

        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /*
     * Signer identifier can contain customer email/mobile.
     *
     * Never expose signer_details through generic model
     * serialization/API responses.
     */
    protected $hidden = [
        'signer_details',
    ];

    public function lead()
    {
        return $this->belongsTo(
            \Modules\BLLeadCreation\Models\BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function isTerminal(): bool
    {
        return in_array(
            $this->status,
            [
                'completed',
                'failed',
                'cancelled',
            ],
            true
        );
    }
}