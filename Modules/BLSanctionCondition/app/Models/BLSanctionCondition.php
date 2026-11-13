<?php

namespace Modules\BLSanctionCondition\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\UserManagement\Models\User;


class BLSanctionCondition extends Model
{
    use SoftDeletes;
    
        protected $table = 'b_l_sanction_conditions';
    
        protected $fillable = [
            'lead_uuid',
            'master_condition_id',
            'condition_title',
            'is_selected',
            'created_by',
        ];
    
        protected $casts = [
            'is_selected' => 'boolean',
        ];
    


    public function masterCondition(): BelongsTo
    {
        return $this->belongsTo(BLSanctionConditionMaster::class, 'master_condition_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
