<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;


class BLLeadAssignment extends Model
{
     use HasFactory;

    protected $table = 'b_l_lead_assignments';

    protected $fillable = [
        'lead_uuid',
        'assigned_by_user_id',
        'assigned_to_user_id',
        'assigned_role',
        'assignment_status',
        'is_active',
        'assigned_at',
        'completed_at',
        'remarks',
    ];

    protected $casts = [
        'lead_uuid' => 'string',
        'assigned_by_user_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'is_active' => 'boolean',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_COMPLETED = 'COMPLETED';
    public const STATUS_CANCELLED = 'CANCELLED';
    public const STATUS_REASSIGNED = 'REASSIGNED';

   
    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

  
    public function assignedBy(): BelongsTo
{
    return $this->belongsTo(
        \Modules\UserManagement\Models\User::class,
        'assigned_by_user_id',
        'id'
    );
}

   
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(
           \Modules\UserManagement\Models\User::class,
            'assigned_to_user_id',
            'id'
        );
    }

  
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where('assignment_status', self::STATUS_ACTIVE);
    }

  
    public function scopeByLeadUuid($query, string $leadUuid)
    {
        return $query->where('lead_uuid', $leadUuid);
    }

  
    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to_user_id', $userId);
    }

  
    public function scopeRole($query, string $role)
    {
        return $query->where('assigned_role', $role);
    }

 
    public function scopeStatus($query, string $status)
    {
        return $query->where('assignment_status', $status);
    }
}
