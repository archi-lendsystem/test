<?php

namespace Modules\Repayment\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepaymentImportInstallmentResource extends JsonResource
{
    /**
     * Transform one staged targeted EMI collection into
     * frontend validation-preview/error-report format.
     *
     * IMPORTANT:
     *
     * This resource represents staging data from
     * repayment_import_installments.
     *
     * It is NOT the live repayment_installments ledger row.
     */
    public function toArray(Request $request): array
    {
        return [
            /*
             * =====================================================
             * EXCEL LOCATION
             * =====================================================
             *
             * Example:
             *
             * EMI #1 collection input may have come from column M.
             */
            'excel_column' =>
                $this->excel_column,

            /*
             * =====================================================
             * TARGETED LIVE EMI IDENTITY
             * =====================================================
             *
             * Internal numeric staging/live IDs are intentionally
             * not exposed.
             */
            'repayment_installment_uuid' =>
                $this->repayment_installment_uuid,

            'installment_number' =>
                $this->installment_number !== null
                    ? (int) $this->installment_number
                    : null,

            /*
             * =====================================================
             * PROTECTED EMI SNAPSHOT
             * =====================================================
             */

            'snapshot_schedule_version' =>
                $this->snapshot_schedule_version !== null
                    ? (int) $this->snapshot_schedule_version
                    : null,

            'snapshot_due_date' =>
                optional(
                    $this->snapshot_due_date
                )->format('Y-m-d'),

            /*
             * Exact financial decimal strings.
             *
             * Do not convert to float/int because these values
             * participate in financial validation/audit.
             */
            'snapshot_installment_amount' =>
                $this->moneyOrNull(
                    $this->snapshot_installment_amount
                ),

            'snapshot_principal_due' =>
                $this->moneyOrNull(
                    $this->snapshot_principal_due
                ),

            'snapshot_interest_due' =>
                $this->moneyOrNull(
                    $this->snapshot_interest_due
                ),

            'snapshot_principal_paid' =>
                $this->moneyOrNull(
                    $this->snapshot_principal_paid
                ),

            'snapshot_interest_paid' =>
                $this->moneyOrNull(
                    $this->snapshot_interest_paid
                ),

            'snapshot_outstanding_amount' =>
                $this->moneyOrNull(
                    $this->snapshot_outstanding_amount
                ),

            'snapshot_payment_status' =>
                $this->snapshot_payment_status,

            'snapshot_due_status' =>
                $this->snapshot_due_status,

            /*
             * =====================================================
             * USER-ENTERED TARGETED COLLECTION
             * =====================================================
             */
            'collection_amount' =>
                $this->moneyOrNull(
                    $this->collection_amount
                ),

            /*
             * =====================================================
             * EMI VALIDATION RESULT
             * =====================================================
             *
             * VALID
             * ERROR
             */
            'validation_status' =>
                $this->validation_status->value,

            'validation_status_label' =>
                $this->validation_status->label(),

            'is_valid' =>
                $this->isValid(),

            'has_errors' =>
                $this->hasErrors(),

            /*
             * Example:
             *
             * [
             *     {
             *         "field": "collection_amount",
             *         "code": "AMOUNT_EXCEEDS_INSTALLMENT_OUTSTANDING",
             *         "message": "..."
             *     }
             * ]
             */
            'validation_errors' =>
                $this->validation_errors ?? [],

            /*
             * Exact ExcelJS EMI source object.
             *
             * Useful for:
             *
             * - frontend preview
             * - error Excel generation
             * - malformed value visibility
             *
             * Financial posting must NEVER trust raw_payload.
             */
            'raw_payload' =>
                $this->raw_payload ?? [],

            /*
             * metadata intentionally NOT exposed.
             *
             * It may contain internal diagnostic values such as
             * resolved live database IDs.
             */

            /*
             * Populated after successful final targeted posting.
             */
            'imported_at' =>
                optional(
                    $this->imported_at
                )->toIso8601String(),
        ];
    }

    /**
     * Return exact decimal value without float conversion.
     */
    private function moneyOrNull(
        mixed $value
    ): ?string {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }
}