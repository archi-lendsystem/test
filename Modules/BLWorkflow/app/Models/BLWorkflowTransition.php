<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;


class BLWorkflowTransition extends Model
{
    use HasFactory;

    protected $table = 'b_l_workflow_transitions';

    protected $fillable = [
        'from_stage_id',
        'to_stage_id',
        'allow_back',
        'required_status_code',
    ];

    protected $casts = [
        'from_stage_id' => 'integer',
        'to_stage_id' => 'integer',
        'allow_back' => 'boolean',
    ];

    public function fromStage()
    {
        return $this->belongsTo(BLWorkflowStage::class, 'from_stage_id');
    }

    public function toStage()
    {
        return $this->belongsTo(BLWorkflowStage::class, 'to_stage_id');
    }
}
