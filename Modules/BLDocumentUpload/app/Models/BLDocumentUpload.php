<?php

namespace Modules\BLDocumentUpload\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\BLDocumentCheckList\Models\BLDocument;
use Modules\BLDocumentCheckList\Models\BLDocumentCheckList;
use Modules\BLDocumentCheckList\Models\BLDocumentGroup;
use Modules\UserManagement\Models\User;

class BLDocumentUpload extends Model
{
    use HasFactory;

    protected $table = 'b_l_document_uploads';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'created_by',
        'lead_id',
        'applicant_sno',
        'applicant_types',
        'document_checklist_uuid',
        'document_group_uuid',
        'document_uuid',
        'document_group',
        'document_name',
        'document',
        'verification_status',
        'verified_by',
        'verified_at',
        'rejection_remarks',
    ];

    protected $casts = [
        'created_by' => 'integer',
        'verified_by' => 'integer',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (BLDocumentUpload $upload) {
            if (empty($upload->uuid)) {
                $upload->uuid = (string) Str::uuid();
            }
        });
    }

    public function checklist(): BelongsTo
    {
        return $this->belongsTo(
            BLDocumentCheckList::class,
            'document_checklist_uuid',
            'uuid'
        );
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(
            BLDocumentGroup::class,
            'document_group_uuid',
            'uuid'
        );
    }

    public function checklistDocument(): BelongsTo
    {
        return $this->belongsTo(
            BLDocument::class,
            'document_uuid',
            'uuid'
        );
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}