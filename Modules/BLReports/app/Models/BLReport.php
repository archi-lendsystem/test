<?php

namespace Modules\BLReports\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\BLDisbursment\Models\BLDisbursment;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Enums\BLReportType;
use Modules\UserManagement\Models\User;

class BLReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'b_l_reports';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'uuid',
        'lead_uuid',
        'disbursement_id',
        'loan_number',
        'report_type',
        'document_name',
        'version',
        'status',
        'storage_disk',
        'file_path',
        'mime_type',
        'file_size',
        'checksum',
        'failure_reason',
        'generated_by',
        'generation_started_at',
        'generated_at',
        'failed_at',
    ];

    protected $casts = [
        'report_type' => BLReportType::class,
        'status' => BLReportStatus::class,
        'disbursement_id' => 'integer',
        'version' => 'integer',
        'file_size' => 'integer',
        'generated_by' => 'integer',
        'generation_started_at' => 'datetime',
        'generated_at' => 'datetime',
        'failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (BLReport $report): void {
            if (empty($report->uuid)) {
                $report->uuid = (string) Str::uuid();
            }

            if (
                empty($report->document_name)
                && $report->report_type instanceof BLReportType
            ) {
                $report->document_name =
                    $report->report_type->label();
            }
        });
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(
            BLLeadCreation::class,
            'lead_uuid',
            'uuid'
        );
    }

    public function disbursement(): BelongsTo
    {
        return $this->belongsTo(
            BLDisbursment::class,
            'disbursement_id',
            'id'
        );
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'generated_by',
            'id'
        );
    }

    public function scopeForLead(
        Builder $query,
        string $leadUuid
    ): Builder {
        return $query->where('lead_uuid', $leadUuid);
    }

    public function scopeForType(
        Builder $query,
        BLReportType|string $type
    ): Builder {
        $reportType = $type instanceof BLReportType
            ? $type->value
            : $type;

        return $query->where('report_type', $reportType);
    }

    public function scopeGenerated(Builder $query): Builder
    {
        return $query->where(
            'status',
            BLReportStatus::GENERATED->value
        );
    }

    public function scopeLatestVersion(Builder $query): Builder
    {
        return $query
            ->orderByDesc('version')
            ->orderByDesc('id');
    }

    public function isGenerated(): bool
    {
        return $this->status === BLReportStatus::GENERATED;
    }

    public function isDownloadable(): bool
    {
        return $this->isGenerated()
            && ! empty($this->file_path);
    }
}