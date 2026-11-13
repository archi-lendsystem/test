<?php

namespace Modules\BLReferenceDetails\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;


class BLReferenceDetails extends Model
{
     use HasFactory;
    use SoftDeletes;

    protected $table = 'b_l_reference_details';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'created_by',
        'lead_id',
        'reference_id',
        'reference_sno',
        'reference_type',
        'full_name',
        'mobile_no',
        'email',
        'gender',
        'relationship',
        'address',
        'pincode',
        'district',
        'city',
        'state',
        'status',
        'remarks',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];



public function verification()
{
    return $this->hasOne(
        \Modules\BLReferenceVerification\Models\BLReferenceVerification::class,
        'reference_detail_id',
        'id'
    );
}
}
