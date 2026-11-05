<?php

namespace Modules\UserManagement\Models;

use Modules\UserManagement\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\UserManagment\Models\VendorType;

class UserVendor extends Model
{
    use SoftDeletes;

    protected $table = 'user_vendors';

    protected $fillable = [
        'user_id',
        'vendor_id',
        'vendor_type_id',
    ];

    protected $dates = [
        'deleted_at',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    // Main user
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Vendor (also from users table)
    public function vendor()
    {
        return $this->belongsTo(User::class, 'vendor_id');
    }

    // Vendor Type
    public function vendorType()
    {
        return $this->belongsTo(\Modules\UserManagement\Models\VendorType::class, 'vendor_type_id');
    }
}
