<?php

namespace Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\UserManagement\Models\Permission;
use Modules\UserManagement\Models\UserPermission;
use Modules\UserManagement\Models\UserType;
use Modules\UserManagement\Models\LoginOtpVerification;


use Modules\Product\Models\Product;


class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes, HasApiTokens;

    protected $fillable = [

        'uuid',

        'user_type_id',

        'user_code',

        'user_name',

        'user_email',
  'user_password',
        'user_mobile_number',

        'user_company_name',

        'department_id',

        'user_designation',

        'organization_id',

        'branch_id',

        'address',

        'pincode',

        'district',

        'city',

        'state',

        'is_active',

        'parent_id',

        'report_to',

        'created_by',
    ];

    protected $hidden = [

        'password',

        'remember_token',
    ];

    protected $casts = [

        'is_active' => 'boolean',
    ];

  

    protected static function boot()
{
    parent::boot();

    static::creating(function (User $user) {
        $prefixMap = [
            2 => 'P',
            3 => 'L', 
            4 => 'T',
            5 => 'C',
        ];

        $userTypeId = $user->user_type_id;

        if (isset($prefixMap[$userTypeId]) && empty($user->user_code)) {
            $prefix = $prefixMap[$userTypeId];

            $nextNumber = static::where('user_type_id', $userTypeId)->count() + 1;

            $user->user_code = $prefix . '-' . str_pad(
                $nextNumber,
                5,
                '0',
                STR_PAD_LEFT
            );
        }
    });
}

  

    public function userType(): BelongsTo
    {
        return $this->belongsTo(
            UserType::class,
            'user_type_id'
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'parent_id'
        );
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'report_to'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function children(): HasMany
    {
        return $this->hasMany(
            User::class,
            'parent_id'
        );
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(
            User::class,
            'report_to'
        );
    }

  

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\UserManagement\Models\Role::class,
            'user_roles',
            'user_id',
            'role_id'
        )->withTimestamps();
    }

   

    public function getPrimaryRoleAttribute()
    {
        return $this->roles->first();
    }

    

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\Product\Models\Product::class,
            'user_products',
            'user_id',
            'product_id'
        )->withTimestamps();
    }


    public function vendors(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_vendors',
            'user_id',
            'vendor_id'
        )
        ->withPivot('vendor_type_id')
        ->withTimestamps();
    }

    

    public function otpVerifications(): HasMany
    {
        return $this->hasMany(
            LoginOtpVerification::class,
            'user_id'
        );
    }


public function permissions(): BelongsToMany
{
    return $this->belongsToMany(
        Permission::class,           
        'user_permissions',
        'user_id',
        'permission_id'
    )->withTimestamps();
}

public function userPermissions()
{
    return $this->hasMany(UserPermission::class);
}

public function hasPermission(string $permission): bool
{
    return $this->permissions()
        ->where('slug', $permission)
        ->exists();
}

public function branch(): BelongsTo
{
    return $this->belongsTo(OrganizationBranch::class, 'branch_id');
}

/**
 * All branches this user is allowed to operate in. branch_id above stays
 * the user's primary/default branch (and the only one for a Branch
 * Manager); this pivot is the authoritative multi-branch list.
 */
public function branches(): BelongsToMany
{
    return $this->belongsToMany(
        OrganizationBranch::class,
        'user_branches',
        'user_id',
        'branch_id'
    )->withTimestamps();
}
}