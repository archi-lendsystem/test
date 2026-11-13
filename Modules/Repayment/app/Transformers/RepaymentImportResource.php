<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentImportResource extends JsonResource
{
    /**
     * Transform one repayment Excel import into
     * validation-preview / import-status API format.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * =====================================================
             * PUBLIC IMPORT IDENTITY
             * =====================================================
             *
             * Internal repayment_imports.id is intentionally
             * not exposed.
             */
            'uuid' =>
                $this->uuid,

            /*
             * =====================================================
             * TEMPLATE INFORMATION
             * =====================================================
             *
             * V3 represents vertical FIFO collection import.
             */
            'template_version' =>
                (int)
                $this->template_version,

            'original_filename' =>
                $this->original_filename,

            /*
             * =====================================================
             * IMPORT LIFECYCLE
             * =====================================================
             *
             * VALIDATING
             * VALIDATED
             * INVALID
             * IMPORTING
             * IMPORTED
             * FAILED
             */
            'status' =>
                $this->status->value,

            'status_label' =>
                $this->status->label(),

            /*
             * =====================================================
             * FRONTEND WORKFLOW FLAGS
             * =====================================================
             *
             * Final confirmation service still performs complete
             * live validation again under DB locks.
             */
            'can_confirm' =>
                $this->canBeImported()
                && (int)
                    $this->error_rows === 0,

            'has_errors' =>
                $this->hasErrors(),

            'is_imported' =>
                $this->isImported(),

            /*
             * =====================================================
             * VALIDATION / COLLECTION SUMMARY
             * =====================================================
             */
            'summary' => [
                /*
                 * Account/Excel rows.
                 */
                'total_rows' =>
                    (int)
                    $this->total_rows,

                'valid_rows' =>
                    (int)
                    $this->valid_rows,

                'error_rows' =>
                    (int)
                    $this->error_rows,

                /*
                 * Exact decimal string.
                 *
                 * V3 total gross amount collected from Excel.
                 */
                'total_amount' =>
                    (string)
                    $this->total_amount,

            ],

            /*
             * =====================================================
             * SAFE TEMPLATE AUDIT INFORMATION
             * =====================================================
             *
             * Complete metadata is intentionally NOT exposed.
             */
            'template_generated_at' =>
                data_get(
                    $this->metadata,
                    'template_generated_at'
                ),

            'import_format' =>
                data_get(
                    $this->metadata,
                    'import_format'
                ),

            'validated_at' =>
                optional(
                    $this->validated_at
                )->toIso8601String(),

            'imported_at' =>
                optional(
                    $this->imported_at
                )->toIso8601String(),

            'created_at' =>
                optional(
                    $this->created_at
                )->toIso8601String(),

            /*
             * =====================================================
             * ACCOUNT ROWS + TARGETED EMIS
             * =====================================================
             *
             * RepaymentImportService now eager-loads:
             *
             * rows
             *   -> installments
             *
             * RepaymentImportRowResource then transforms each
             * selected EMI.
             */
            'rows' =>
                $this->whenLoaded(
                    'rows',
                    fn () =>
                        RepaymentImportRowResource::
                            collection(
                                $this->rows
                            )
                ),
        ];
    }

    /**
     * Return an integer metadata value when present.
     *
     * Null remains null so historical V1 imports that do not
     * contain EMI-level summary metadata remain backward-safe.
     */
    private function metadataInteger(
        string $path
    ): ?int {
        $value =
            data_get(
                $this->metadata,
                $path
            );

        if ($value === null) {
            return null;
        }

        return (int) $value;
    }
}


