<?php

namespace Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VendorType extends Model
{
    use SoftDeletes;

    protected $table = 'vendor_types';

    protected $fillable = [
        'name',
    ];

    protected $dates = [
        'deleted_at',
    ];
}
