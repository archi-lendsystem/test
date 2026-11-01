<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLStageRolePermission extends Model
{
   use HasFactory;

    protected $table = 'b_l_stage_role_permissions';

    protected $fillable = [
        'stage_id',
        'role_name',
        'can_view',
        'can_edit',
        'can_assign',
        'can_change_status',
        'can_upload_document',
        'can_move_next',
        'can_move_previous',
    ];

    protected $casts = [
        'stage_id' => 'integer',
        'can_view' => 'boolean',
        'can_edit' => 'boolean',
        'can_assign' => 'boolean',
        'can_change_status' => 'boolean',
        'can_upload_document' => 'boolean',
        'can_move_next' => 'boolean',
        'can_move_previous' => 'boolean',
    ];

    public function stage()
    {
        return $this->belongsTo(BLWorkflowStage::class, 'stage_id');
    }
}
