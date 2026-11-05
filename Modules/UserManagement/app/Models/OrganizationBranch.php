<?php

namespace Modules\UserManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganizationBranch extends Model
{
    use SoftDeletes;

    protected $table = 'organization_branches';

    protected $fillable = [
        'organization_id',
        'user_type_id',
        'parent_branch_id',
        'name',
        'branch_code',
        'type',
        'address',
        'pincode',
        'city',
        'state',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class); 
    }

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function parentBranch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_branch_id');
    }

    public function childBranches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_branch_id');
    }
    public function user()
{
    return $this->belongsTo(User::class, 'user_id');  
}
}