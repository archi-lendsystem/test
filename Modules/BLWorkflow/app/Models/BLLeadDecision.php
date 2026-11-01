<?php

namespace Modules\BLWorkflow\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\UserManagement\Models\User;
// use Modules\BLWorkflow\Database\Factories\BLLeadDecisionFactory;

class BLLeadDecision extends Model
{
     protected $table = 'b_l_lead_decisions';

    protected $fillable = [
        'lead_uuid',
        'stage_code',
        'decision',
        'remarks',
        'decision_by',
        'decision_at',
        'is_latest',
        'reopened_by',
        'reopened_at',
        'reopen_remarks',
    ];

    protected $casts = [
        'is_latest'    => 'boolean',
        'decision_at'  => 'datetime',
        'reopened_at'  => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function decisionByUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'decision_by'
        );
    }

    public function reopenedByUser(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'reopened_by'
        );
    }
}
