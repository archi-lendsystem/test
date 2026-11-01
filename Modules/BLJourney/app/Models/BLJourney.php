<?php

namespace Modules\BLJourney\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\UserManagement\Models\User;
use Modules\BLWorkflow\Models\BLWorkflowStage;
use Modules\BLWorkflow\Models\BLWorkflowStatus;


class BLJourney extends Model
{
    protected $fillable = [
        'lead_id',
        'lead_uuid',
        'workflow_stage_id',
        'workflow_status_id',
        'previous_stage_id',
        'stage',
        'status',
        'team',
        'action_type',
        'transition_type',
        'performed_by_role',
        'action_performed',
        'remarks',
        'user_id',
        'user_name',
        'is_latest',
        'ip_address',
        'device_info',
    ];

    protected $casts = [
        'is_latest' => 'boolean',
    ];

   
    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(
            BLWorkflowStage::class,
            'workflow_stage_id'
        );
    }

 
    public function workflowStatus(): BelongsTo
    {
        return $this->belongsTo(
            BLWorkflowStatus::class,
            'workflow_status_id'
        );
    }

    public function previousStage(): BelongsTo
    {
        return $this->belongsTo(
            BLWorkflowStage::class,
            'previous_stage_id'
        );
    }

 
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
