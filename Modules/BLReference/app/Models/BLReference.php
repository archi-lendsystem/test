<?php

namespace Modules\BLReference\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
// use Modules\BLReference\Database\Factories\BLReferenceFactory;

class BLReference extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'b_l_references';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'lead_uuid',
        'created_by',
        'lead_id',
        'reference_sno',
        'reference_types',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
