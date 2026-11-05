<?php

namespace Modules\BLDocumentCheckList\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class BLDocument extends Model
{

    protected $table = 'b_l_documents';

    protected $primaryKey = 'id';
    public $incrementing = true;
    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'document_checklist_id',
        'document_group_id',
        'dg_document_class',
        'dg_document_name',
        'dg_rule',
        'document_sequence',
    ];

    protected $casts = [
        'document_sequence' => 'string',
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

    public function documentGroup()
    {
        return $this->belongsTo(
            BLDocumentGroup::class,
            'document_group_id',
            'uuid'
        );
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}