<?php

namespace Modules\BLDocumentCheckList\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class BLDocumentGroup extends Model
{
    use HasFactory;

    protected $table = 'b_l_document_groups';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'document_checklist_id',
        'dg_document_group_name',
        'dg_group_sequence',
        'dg_mandatory',
    ];

    protected $casts = [
        'dg_group_sequence' => 'integer',
        'created_at'        => 'datetime',
        'updated_at'        => 'datetime',
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

    public function documentCheckList()
    {
        return $this->belongsTo(
            BLDocumentCheckList::class,
            'document_checklist_id',
            'uuid'
        );
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}