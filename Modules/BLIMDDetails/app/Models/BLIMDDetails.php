<?php

namespace Modules\BLIMDDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLProperty\Models\BLProperty;
use Modules\UserManagement\Models\User;


class BLIMDDetails extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'b_l_i_m_d_details';

    protected $primaryKey = 'id';

    protected $fillable = [
        'lead_uuid',
        'lead_id',
        'created_by',
        'imd_waiver',
        'imd_waiver_remarks',
        'imd_instrument_type',
        'imd_amount',
        'imd_clearing_date',
        'imd_payment_transaction_number',
        'imd_status',
        'imd_picture',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function leadCreation(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by',
            'id'
        );
    }
}
