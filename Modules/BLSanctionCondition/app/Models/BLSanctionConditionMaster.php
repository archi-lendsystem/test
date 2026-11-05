<?php

namespace Modules\BLSanctionCondition\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;


class BLSanctionConditionMaster extends Model
{
    use SoftDeletes;

    protected $table = 'b_l_sanction_condition_masters';

    protected $fillable = ['condition_id', 'title', 'is_active', 'created_by'];

    protected $casts = ['is_active' => 'boolean'];

    public function selectedConditions(): HasMany
    {
        return $this->hasMany(BLSanctionCondition::class, 'master_condition_id');
    }
}
