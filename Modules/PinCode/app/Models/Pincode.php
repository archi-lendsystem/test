<?php

namespace Modules\PinCode\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\PinCode\Database\Factories\PincodeFactory;

class Pincode extends Model
{
    use HasFactory;

    protected $table = 'pincodes';

    protected $primaryKey = 'pincode';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'pincode',
        'state',
        'districts',
        'cities',
        'post_offices',
        'last_fetched',
    ];

    protected $casts = [
        'districts' => 'array',
        'cities' => 'array',
        'post_offices' => 'array',
        'last_fetched' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
