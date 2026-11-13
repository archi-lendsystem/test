<?php

namespace Modules\Repayment\Services;

use Illuminate\Support\Facades\DB;
use Modules\Repayment\Enums\RepaymentImportStatus;
use Modules\Repayment\Exceptions\RepaymentImportException;
use Modules\Repayment\Http\Requests\ValidateRepaymentImportRequest;
use Modules\Repayment\Models\RepaymentImport;
use Modules\Repayment\Models\RepaymentImportRow;

final class RepaymentImportService
{
    public function __construct(private readonly RepaymentImportValidationService $validationService) {}

    public function validateAndStage(array $payload, ?int $uploadedBy = null): RepaymentImport
    {
        $version = (int) ($payload['template_version'] ?? 0);
        if ($version !== ValidateRepaymentImportRequest::TEMPLATE_VERSION) {
            throw RepaymentImportException::unsupportedTemplateVersion(
                $version,
                ValidateRepaymentImportRequest::TEMPLATE_VERSION
            );
        }

        $result = $this->validationService->validateRows($payload['rows'] ?? []);

        return DB::transaction(function () use ($payload, $uploadedBy, $version, $result): RepaymentImport {
            $import = RepaymentImport::query()->create([
                'template_version' => $version,
                'original_filename' => $payload['original_filename'] ?? null,
                'status' => $result['error_rows'] > 0
                    ? RepaymentImportStatus::INVALID->value
                    : RepaymentImportStatus::VALIDATED->value,
                'total_rows' => $result['total_rows'],
                'valid_rows' => $result['valid_rows'],
                'error_rows' => $result['error_rows'],
                'total_amount' => $result['total_amount'],
                'uploaded_by' => $uploadedBy,
                'validated_at' => now(),
                'metadata' => [
                    'template_generated_at' => $payload['template_generated_at'] ?? null,
                    'import_format' => 'VERTICAL_FIFO_COLLECTION',
                    'gst_percentage' => '18.00',
                ],
            ]);

            foreach ($result['rows'] as $row) {
                RepaymentImportRow::query()->create(array_merge($row, [
                    'repayment_import_id' => $import->id,
                    'collected_by' => $uploadedBy,
                ]));
            }

            return $this->findWithRows((string) $import->uuid);
        }, 3);
    }

    public function findByUuid(string $uuid): RepaymentImport
    {
        $import = RepaymentImport::query()->where('uuid', $uuid)->first();
        if (! $import) throw RepaymentImportException::importNotFound($uuid);
        return $import;
    }

    public function findWithRows(string $uuid): RepaymentImport
    {
        $import = $this->findByUuid($uuid);
        return $import->load([
            'rows' => fn ($query) => $query->orderBy('row_number'),
            'rows.transaction',
        ]);
    }
}


