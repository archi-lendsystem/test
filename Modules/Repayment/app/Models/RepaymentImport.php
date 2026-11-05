<?php

namespace Modules\Repayment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Repayment\Enums\RepaymentImportStatus;
use Modules\Repayment\Models\RepaymentImportRow;

class RepaymentImport extends Model
{
    use HasUuids;

    /**
     * Database table.
     */
    protected $table = 'repayment_imports';

    /**
     * Mass assignable attributes.
     */
    protected $fillable = [
        'template_version',
        'original_filename',
        'status',

        'total_rows',
        'valid_rows',
        'error_rows',
        'total_amount',

        'uploaded_by',

        'validated_at',
        'imported_at',

        'metadata',
    ];

    /**
     * Default values before persistence.
     */
    protected $attributes = [
        'template_version' => 1,
        'status' => 'VALIDATING',

        'total_rows' => 0,
        'valid_rows' => 0,
        'error_rows' => 0,
        'total_amount' => 0,
    ];

    /**
     * Attribute casts.
     */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',

            'status' => RepaymentImportStatus::class,

            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'error_rows' => 'integer',

            'total_amount' => 'decimal:2',

            'uploaded_by' => 'integer',

            'validated_at' => 'datetime',
            'imported_at' => 'datetime',

            'metadata' => 'array',
        ];
    }

    /**
     * Generate UUID only for the uuid column.
     *
     * Numeric id remains the primary key.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Public route binding should use UUID.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Excel rows belonging to this import.
     */
    public function rows(): HasMany
    {
        return $this->hasMany(
            RepaymentImportRow::class,
            'repayment_import_id'
        );
    }

    /**
     * Check whether the batch is ready for final import.
     */
    public function canBeImported(): bool
    {
        return $this->status->canBeImported();
    }

    /**
     * Check whether the validation produced errors.
     */
    public function hasErrors(): bool
    {
        return $this->error_rows > 0
            || $this->status === RepaymentImportStatus::INVALID;
    }

    /**
     * Check whether the import has completed successfully.
     */
    public function isImported(): bool
    {
        return $this->status->isImported();
    }
}