<?php

namespace Modules\PropertyVisitReport\App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\PropertyVisitReport\App\Models\PropertyVisitReport;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class PropertyVisitReportService
{
    /**
     * Check whether lead can use Property Visit Report.
     */
    public function checkEligibility(string $leadUuid): array
    {
        $lead = DB::table('b_l_lead_creations')
            ->where('uuid', $leadUuid)
            ->whereNull('deleted_at')
            ->first();

        if (!$lead) {
            throw new NotFoundHttpException(
                'Lead not found.'
            );
        }

        if (empty($lead->product_id)) {
            return [
                'allowed' => false,
                'message' => 'Product is not assigned to this lead.',
                'lead_uuid' => $leadUuid,
                'product_id' => null,
                'product_type' => null,
            ];
        }

        $product = DB::table('products')
            ->where('id', $lead->product_id)
            ->first();

        if (!$product) {
            return [
                'allowed' => false,
                'message' => 'Product not found.',
                'lead_uuid' => $leadUuid,
                'product_id' => $lead->product_id,
                'product_type' => null,
            ];
        }

        $productType = $this->resolveProductType($product);

        $allowed = in_array(
            $productType,
            ['LAP', 'HL'],
            true
        );

        return [
            'allowed' => $allowed,

            'message' => $allowed
                ? 'Property Visit Report is available for this lead.'
                : 'Property Visit Report is available only for LAP or HL.',

            'lead_uuid' => $leadUuid,
            'product_id' => $lead->product_id,
            'product_type' => $productType,
        ];
    }


    /**
     * Create report.
     */
    public function create(
        string $leadUuid,
        array $data,
        array $documents = [],
        array $siteImages = []
    ): PropertyVisitReport {

        $context = $this->getEligibleLead($leadUuid);

        /*
         * Only one report for one Lead UUID.
         */
        $existingReport = PropertyVisitReport::where(
            'lead_uuid',
            $leadUuid
        )->first();

        if ($existingReport) {
            throw new HttpException(
                409,
                'Property Visit Report already exists for this lead.'
            );
        }

        $uploadedDocuments = [];
        $uploadedImages = [];

        try {

            /*
             * Upload documents.
             */
            foreach ($documents as $document) {

                $uploadedDocuments[] = $document->store(
                    "property-visit-reports/{$leadUuid}/documents",
                    'private'
                );
            }

            /*
             * Upload images.
             */
            foreach ($siteImages as $image) {

                $uploadedImages[] = $image->store(
                    "property-visit-reports/{$leadUuid}/site-images",
                    'private'
                );
            }

            unset(
                $data['documents'],
                $data['site_images'],
                $data['remove_documents'],
                $data['remove_site_images'],
                $data['lead_uuid'],
                $data['product_id']
            );

            /*
             * UUID comes from URL.
             * Product comes from Lead.
             */
            $data['lead_uuid'] = $context['lead']->uuid;

            $data['product_id'] =
                $context['lead']->product_id;

            $data['documents'] =
                $uploadedDocuments;

            $data['site_images'] =
                $uploadedImages;

            return DB::transaction(
                function () use ($data) {

                    return PropertyVisitReport::create(
                        $data
                    );
                }
            );

        } catch (Throwable $exception) {

            if (!empty($uploadedDocuments)) {
                Storage::disk('private')
                    ->delete($uploadedDocuments);
            }

            if (!empty($uploadedImages)) {
                Storage::disk('private')
                    ->delete($uploadedImages);
            }

            throw $exception;
        }
    }


    /**
     * Get report using Lead UUID.
     */
    public function findByLeadUuid(
        string $leadUuid
    ): PropertyVisitReport {

        $report = PropertyVisitReport::where(
            'lead_uuid',
            $leadUuid
        )->first();

        if (!$report) {
            throw new NotFoundHttpException(
                'Property Visit Report not found.'
            );
        }

        return $report;
    }


    /**
     * Update using Lead UUID.
     */
    public function update(
        string $leadUuid,
        array $data,
        array $documents = [],
        array $siteImages = []
    ): PropertyVisitReport {

        /*
         * Recheck lead + LAP/HL.
         */
        $context = $this->getEligibleLead(
            $leadUuid
        );

        $report = $this->findByLeadUuid(
            $leadUuid
        );

        $existingDocuments =
            $report->documents ?? [];

        $existingImages =
            $report->site_images ?? [];

        $removeDocumentIndexes =
            collect(
                $data['remove_documents'] ?? []
            )
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();

        $removeImageIndexes =
            collect(
                $data['remove_site_images'] ?? []
            )
            ->map(fn ($value) => (int) $value)
            ->unique()
            ->values()
            ->all();


        /*
         * Files which will be physically deleted
         * after successful DB update.
         */
        $documentsToDelete = [];

        foreach ($removeDocumentIndexes as $index) {
            if (isset($existingDocuments[$index])) {
                $documentsToDelete[] =
                    $existingDocuments[$index];
            }
        }


        $imagesToDelete = [];

        foreach ($removeImageIndexes as $index) {
            if (isset($existingImages[$index])) {
                $imagesToDelete[] =
                    $existingImages[$index];
            }
        }


        /*
         * Keep remaining documents.
         */
        $finalDocuments = [];

        foreach ($existingDocuments as $index => $path) {

            if (!in_array(
                $index,
                $removeDocumentIndexes,
                true
            )) {
                $finalDocuments[] = $path;
            }
        }


        /*
         * Keep remaining images.
         */
        $finalImages = [];

        foreach ($existingImages as $index => $path) {

            if (!in_array(
                $index,
                $removeImageIndexes,
                true
            )) {
                $finalImages[] = $path;
            }
        }


        $newDocuments = [];
        $newImages = [];

        try {

            /*
             * New documents.
             */
            foreach ($documents as $document) {

                $newDocuments[] = $document->store(
                    "property-visit-reports/{$leadUuid}/documents",
                    'private'
                );
            }


            /*
             * New images.
             */
            foreach ($siteImages as $image) {

                $newImages[] = $image->store(
                    "property-visit-reports/{$leadUuid}/site-images",
                    'private'
                );
            }


            $finalDocuments = array_merge(
                $finalDocuments,
                $newDocuments
            );

            $finalImages = array_merge(
                $finalImages,
                $newImages
            );


            unset(
                $data['documents'],
                $data['site_images'],
                $data['remove_documents'],
                $data['remove_site_images'],
                $data['lead_uuid'],
                $data['product_id']
            );


            /*
             * Product always remains synced
             * with Lead.
             */
            $data['product_id'] =
                $context['lead']->product_id;

            $data['documents'] =
                array_values($finalDocuments);

            $data['site_images'] =
                array_values($finalImages);


            DB::transaction(
                function () use (
                    $report,
                    $data
                ) {
                    $report->update($data);
                }
            );


            /*
             * DB successful.
             * Now delete removed private files.
             */
            if (!empty($documentsToDelete)) {
                Storage::disk('private')
                    ->delete($documentsToDelete);
            }

            if (!empty($imagesToDelete)) {
                Storage::disk('private')
                    ->delete($imagesToDelete);
            }


            return $report->refresh();

        } catch (Throwable $exception) {

            /*
             * DB failed.
             * Delete newly uploaded files.
             */
            if (!empty($newDocuments)) {
                Storage::disk('private')
                    ->delete($newDocuments);
            }

            if (!empty($newImages)) {
                Storage::disk('private')
                    ->delete($newImages);
            }

            throw $exception;
        }
    }


    /**
     * Get private document by index.
     */
    public function getDocument(
        string $leadUuid,
        int $index
    ): array {

        $report = $this->findByLeadUuid(
            $leadUuid
        );

        $documents = $report->documents ?? [];

        if (!isset($documents[$index])) {
            throw new NotFoundHttpException(
                'Document not found.'
            );
        }

        $path = $documents[$index];

        if (!Storage::disk('private')->exists($path)) {
            throw new NotFoundHttpException(
                'Document file not found.'
            );
        }

        return [
            'path' => $path,
            'name' => basename($path),
        ];
    }


    /**
     * Get private site image by index.
     */
    public function getSiteImage(
        string $leadUuid,
        int $index
    ): array {

        $report = $this->findByLeadUuid(
            $leadUuid
        );

        $images = $report->site_images ?? [];

        if (!isset($images[$index])) {
            throw new NotFoundHttpException(
                'Site image not found.'
            );
        }

        $path = $images[$index];

        if (!Storage::disk('private')->exists($path)) {
            throw new NotFoundHttpException(
                'Site image file not found.'
            );
        }

        return [
            'path' => $path,
            'name' => basename($path),
        ];
    }


    /**
     * Lead + Product validation.
     */
    private function getEligibleLead(
        string $leadUuid
    ): array {

        $lead = DB::table('b_l_lead_creations')
            ->where('uuid', $leadUuid)
            ->whereNull('deleted_at')
            ->first();

        if (!$lead) {
            throw new NotFoundHttpException(
                'Lead not found.'
            );
        }


        if (empty($lead->product_id)) {
            throw new HttpException(
                422,
                'Product is not assigned to this lead.'
            );
        }


        $product = DB::table('products')
            ->where('id', $lead->product_id)
            ->first();


        if (!$product) {
            throw new NotFoundHttpException(
                'Product not found.'
            );
        }


        $productType =
            $this->resolveProductType($product);


        if (!in_array(
            $productType,
            ['LAP', 'HL'],
            true
        )) {

            throw new HttpException(
                422,
                'Property Visit Report is available only for LAP or HL products.'
            );
        }


        return [
            'lead' => $lead,
            'product' => $product,
            'product_type' => $productType,
        ];
    }


    /**
     * Detect LAP / HL from product.
     *
     * Once you show me your products migration,
     * this method can be reduced to exact column.
     */
    private function resolveProductType(
        object $product
    ): ?string {

        $values = [
            $product->product_code ?? null,
            $product->code ?? null,
            $product->short_name ?? null,
            $product->slug ?? null,
            $product->product_name ?? null,
            $product->name ?? null,
        ];


        foreach ($values as $value) {

            if (!$value) {
                continue;
            }

            $value = strtoupper(
                trim((string) $value)
            );


            if (
                $value === 'LAP'
                || $value === 'LOAN AGAINST PROPERTY'
                || preg_match('/\bLAP\b/', $value)
            ) {
                return 'LAP';
            }


            if (
                $value === 'HL'
                || $value === 'HOME LOAN'
                || preg_match('/\bHL\b/', $value)
            ) {
                return 'HL';
            }
        }


        return null;
    }
}