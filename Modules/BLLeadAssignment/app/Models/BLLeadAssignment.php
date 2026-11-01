<?php

namespace Modules\BLLeadAssignment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;

class BLLeadAssignment extends Model
{
     use HasFactory;

    protected $table = 'b_l_lead_assignments';

    protected $fillable = [
        'lead_uuid',
        'lead_id',

        'assigned_by',
        'assigned_to',
        'assigned_to_role',
        'step_number',
        'type',
        'remark',

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
        'assigned_by_user_id' => 'integer',
        'assigned_to_user_id' => 'integer',
        'step_number' => 'integer',
        'is_active' => 'boolean',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function lead()
    {
        return $this->belongsTo(BLLeadCreation::class, 'lead_uuid', 'uuid');
    }

    public function assignedByUser()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function assignedToUser()
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
