<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLLeadCreation\Models\BLLeadCreation;


class BLJourney extends Model
{
     use HasFactory;

    protected $table = 'b_l_journeys';

    protected $fillable = [
        'lead_uuid',

      
        'workflow_stage_id',
        'workflow_status_id',
        'previous_stage_id',

       
        'action_performed',
        'performed_by_role',
        'transition_type',

        
        'is_latest',
        'locked_at',
    ];

    protected $casts = [
        'lead_uuid' => 'string',
        'workflow_stage_id' => 'integer',
        'workflow_status_id' => 'integer',
        'previous_stage_id' => 'integer',
        'is_latest' => 'boolean',
        'locked_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const TRANSITION_FORWARD = 'FORWARD';
    public const TRANSITION_BACKWARD = 'BACKWARD';
    public const TRANSITION_STATUS_CHANGE = 'STATUS_CHANGE';
    public const TRANSITION_ASSIGNMENT = 'ASSIGNMENT';
    public const TRANSITION_LOCKED = 'LOCKED';

   
    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

   
    public function workflowStage(): BelongsTo
    {
        return $this->belongsTo(
            BLWorkflowStage::class,
            'workflow_stage_id',
            'id'
        );
    }

  
    public function workflowStatus(): BelongsTo
    {
        return $this->belongsTo(
            BLWorkflowStatus::class,
            'workflow_status_id',
            'id'
        );
    }

  
    public function previousStage(): BelongsTo
    {
        return $this->belongsTo(
            BLWorkflowStage::class,
            'previous_stage_id',
            'id'
        );
    }

 
    public function scopeLatestJourney($query)
    {
        return $query->where('is_latest', true);
    }

   
    public function scopeByLeadUuid($query, string $leadUuid)
    {
        return $query->where('lead_uuid', $leadUuid);
    }

  
    public function scopeByStage($query, int $stageId)
    {
        return $query->where('workflow_stage_id', $stageId);
    }

    public function scopeByStatus($query, int $statusId)
    {
        return $query->where('workflow_status_id', $statusId);
    }

  
    public function scopeByTransitionType($query, string $transitionType)
    {
        return $query->where('transition_type', $transitionType);
    }

 
    public function scopeLocked($query)
    {
        return $query->whereNotNull('locked_at');
    }

 
    public function scopeUnlocked($query)
    {
        return $query->whereNull('locked_at');
    }
}
