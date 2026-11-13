<?php

namespace Modules\BLReferenceVerification\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReferenceDetails\Models\BLReferenceDetails;


class BLReferenceVerification extends Model
{
    use HasFactory, SoftDeletes; 

    protected $table = 'b_l_reference_verifications';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'reference_id',
        'reference_detail_id',
        'status',
        'remarks',
        'Known_since',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];


    public function leadCreation()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function referenceDetail()
    {
        return $this->belongsTo(BLReferenceDetails::class, 'reference_detail_id');
    }
}
