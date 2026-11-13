<?php

namespace Modules\Charges\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;
// use Modules\Charges\Database\Factories\ChargeFactory;

class Charge extends Model
{
   use HasFactory, SoftDeletes;

    protected $table = 'charges';

  protected $fillable = [
    'lead_uuid',
    'platform_charges',
    'sms_changes',
    'application_charges',
    'other_charges',
    'total_charges',
];

protected $casts = [
    'platform_charges'    => 'decimal:2',
    'sms_changes'         => 'decimal:2',
    'application_charges' => 'decimal:2',
    'other_charges'       => 'decimal:2',
    'total_charges'       => 'decimal:2',
];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }
}
