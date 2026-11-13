<?php

namespace Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Organization extends Model
{
    use SoftDeletes;

    protected $table = 'organizations';
    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'uuid', 'user_type_id', 'name', 'code', 'gstin', 'pan', 'email', 'phone', 'is_active',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            if (empty($organization->uuid)) {
                $organization->uuid = (string) Str::uuid();
            }
        });
    }

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function adminUser()
{
    return $this->hasOne(
        \Modules\UserManagement\Models\User::class,
        'organization_id',
        'id'
    )
    ->whereIn('user_type_id', [2, 3, 4, 5])
    ->orderBy('created_at', 'asc');
}
}