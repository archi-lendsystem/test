<?php

namespace Modules\BLCaseDiscusion\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLCaseDiscusion extends Model
{
    use HasFactory;

    protected $table = 'b_l_case_discusions';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'lead_id',
        'created_by',
        'status',
        'forwarded_to',
        'forwarded_by',
        'remarks',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];


    public function lead()
    {
        return $this->belongsTo(
            \Modules\BLLeadCreation\Models\BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function createdBy()
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\User::class,
            'created_by',
            'id'
        );
    }

    public function forwardedTo()
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\User::class,
            'forwarded_to',
            'id'
        );
    }

    public function forwardedBy()
    {
        return $this->belongsTo(
            \Modules\UserManagement\Models\User::class,
            'forwarded_by',
            'id'
        );
    }
}
