<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLWorkflowStage extends Model
{
    use HasFactory;

    protected $table = 'b_l_workflow_stages';

    protected $fillable = [
        'stage_code',
        'stage_name',
        'sequence_no',
        'team_name',
        'is_reversible',
        'is_terminal',
        'allow_reassignment',
        'allow_edit',
    ];

    protected $casts = [
        'sequence_no' => 'integer',
        'is_reversible' => 'boolean',
        'is_terminal' => 'boolean',
        'allow_reassignment' => 'boolean',
        'allow_edit' => 'boolean',
    ];

    public function statuses()
    {
        return $this->hasMany(BLWorkflowStatus::class, 'stage_id')->orderBy('id');
    }

    public function transitionsFrom()
    {
        return $this->hasMany(BLWorkflowTransition::class, 'from_stage_id');
    }

    public function transitionsTo()
    {
        return $this->hasMany(BLWorkflowTransition::class, 'to_stage_id');
    }

    public function rolePermissions()
    {
        return $this->hasMany(BLStageRolePermission::class, 'stage_id');
    }
}
