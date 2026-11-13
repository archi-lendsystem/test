<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLWorkflowStatus extends Model
{
    use HasFactory;

    protected $table = 'b_l_workflow_statuses';

    protected $fillable = [
        'stage_id',
        'status_code',
        'status_name',
        'is_final',
    ];

    protected $casts = [
        'stage_id' => 'integer',
        'is_final' => 'boolean',
    ];

    public function stage()
    {
        return $this->belongsTo(BLWorkflowStage::class, 'stage_id');
    }
}
