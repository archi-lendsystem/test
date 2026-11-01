<?php

namespace Modules\BLDocumentCheckList\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class BLDocumentCheckList extends Model
{

    protected $table = 'b_l_document_check_lists';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'title',
        'type',
        'applicable_to',
        'loan_type',
        'is_income_considered',
        'applying_as',
        'employment_type',
        'entity_type',
        'rule',
        'sequence',
        'status',
    ];

    protected $casts = [
        'is_income_considered' => 'boolean',
        'status'               => 'boolean',
        'sequence'             => 'integer',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}