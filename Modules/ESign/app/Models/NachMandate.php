<?php

namespace Modules\ESign\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class NachMandate extends Model
{
    use HasFactory;

    protected $table = 'esign_nach_mandates';

    protected $fillable = [
        'uuid',
        'lead_uuid',
        'module_type',
        'module_id',
        'digio_mandate_id',
        'umrn',
        'customer_name',
        'customer_identifier',
        'bank_account_number',
        'ifsc_code',
        'amount',
        'frequency',
        'auth_mode',
        'status',
        'authorization_url',
        'failure_reason',
        'initiated_by',
        'registered_at',
        'cancelled_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'registered_at' => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(\Modules\BLLeadCreation\Models\BLLeadCreation::class, 'lead_uuid', 'uuid');
    }
}
