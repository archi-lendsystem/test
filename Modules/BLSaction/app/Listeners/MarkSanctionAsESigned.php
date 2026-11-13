<?php

namespace Modules\BLSaction\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BLSaction\Models\BLSaction;
use Modules\ESign\Events\ESignCompleted;

class MarkSanctionAsESigned
{
    public function handle(
        ESignCompleted $event
    ): void {
        $signingRequest = $event->signingRequest;

        /*
         * This listener is ONLY for BL Sanction Letter eSign.
         *
         * BL Reports and every other eSign document
         * are ignored here.
         */
        if (
            $signingRequest->module_type !== 'bl'
            || $signingRequest->document_type !== 'sanction_letter'
        ) {
            return;
        }

        /*
         * Never mark Sanction as eSigned unless:
         * 1. Common eSign request is completed.
         * 2. Signed PDF exists logically.
         * 3. Signed PDF checksum exists.
         */
        if (
            $signingRequest->status !== 'completed'
            || empty($signingRequest->signed_pdf_path)
            || empty($signingRequest->signed_pdf_checksum)
        ) {
            return;
        }

        if (empty($signingRequest->module_id)) {
            Log::warning(
                'Unable to synchronize completed eSign with Sanction.',
                [
                    'signing_request_uuid' => $signingRequest->uuid,
                    'reason' => 'module_id_missing',
                ]
            );

            return;
        }

        /*
         * IMPORTANT BUSINESS SAFETY:
         *
         * Only:
         *
         * esign_pending -> esigned
         *
         * is allowed.
         *
         * generated / confirmed / rejected / esigned
         * will never be overwritten.
         */
        $query = BLSaction::query()
            ->whereKey($signingRequest->module_id)
            ->where('status', 'esign_pending');

        /*
         * Additional cross-lead protection.
         */
        if (! empty($signingRequest->lead_uuid)) {
            $query->where(
                'lead_uuid',
                $signingRequest->lead_uuid
            );
        }

        $updated = $query->update([
            'status' => 'esigned',
        ]);

        if ($updated === 1) {
            Log::info(
                'Sanction marked as eSigned.',
                [
                    'signing_request_uuid' => $signingRequest->uuid,
                    'sanction_id' => $signingRequest->module_id,
                    'lead_uuid' => $signingRequest->lead_uuid,
                ]
            );
        }
    }
}