<?php

namespace Modules\BLSaction\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\BLSaction\Models\BLSaction;
use Modules\ESign\Enums\DocumentStatus;
use Modules\ESign\Events\ESignCompleted;
use Modules\ESign\Services\BLCombinedDocumentService;

class MarkSanctionAsESigned
{
    public function handle(
        ESignCompleted $event
    ): void {
        /*
         * New ESignCompleted event contains
         * the ESignDocument.
         */
        $document = $event->document;

        /*
         * Ignore normal agreements or any other
         * eSign document.
         *
         * This listener is interested only in
         * the combined BL package.
         */
        if (
            $document->document_type
                !== BLCombinedDocumentService::DOCUMENT_TYPE
            || empty($document->lead_uuid)
            || $document->status
                !== DocumentStatus::Completed
            || empty(
                $document->final_signed_document_path
            )
        ) {
            return;
        }

        /*
         * IMPORTANT:
         *
         * The sanction is marked "esigned" only after
         * the complete sequence finishes:
         *
         * borrower signed
         *      ↓
         * lender signed
         *      ↓
         * ESignDocument = completed
         *      ↓
         * sanction = esigned
         *
         * We never mark it eSigned after borrower
         * signature alone.
         */
        $updated = BLSaction::query()
            ->where(
                'lead_uuid',
                $document->lead_uuid
            )
            ->where(
                'status',
                'esign_pending'
            )
            ->latest('id')
            ->limit(1)
            ->update([
                'status' =>
                    'esigned',
            ]);

        if ($updated > 0) {
            Log::info(
                'Sanction marked as eSigned from completed combined BL package.',
                [
                    'esign_document_uuid' =>
                        $document->uuid,

                    'lead_uuid' =>
                        $document->lead_uuid,
                ]
            );
        }
    }
}