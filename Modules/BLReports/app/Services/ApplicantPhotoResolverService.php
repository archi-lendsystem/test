<?php

namespace Modules\BLReports\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\BLApplicant\Models\BLApplicant;
use Modules\BLApplicantDetails\Models\BLApplicantDetails;
use Modules\BLDocumentUpload\Models\BLDocumentUpload;
use Modules\BLLeadCreation\Models\BLLeadCreation;
use Throwable;

final class ApplicantPhotoResolverService
{
    private const STORAGE_DISK = 'private';

    private const PROFILE_DOCUMENT_NAME = 'Profile Picture';

    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Resolve applicant or co-applicant photo.
     *
     * Priority:
     * 1. BLApplicantDetails.profile_photo
     * 2. BLDocumentUpload Profile Picture
     * 3. No photo
     */
    public function resolve(
        BLLeadCreation $lead,
        BLApplicant $applicant,
        ?BLApplicantDetails $applicantDetails = null
    ): array {
        $applicantDetails ??= BLApplicantDetails::query()
            ->where('lead_uuid', $lead->uuid)
            ->where('applicant_id', $applicant->id)
            ->first();

        /*
         * First priority: Applicant Details photo.
         */
        $detailsPhoto = $this->buildPhotoPayload(
            path: $applicantDetails?->profile_photo,
            source: 'applicant_details',
            leadUuid: (string) $lead->uuid,
            applicantId: (int) $applicant->id
        );

        if ($detailsPhoto !== null) {
            return $detailsPhoto;
        }

        /*
         * Second priority: Document Upload profile picture.
         *
         * We check records from latest to oldest and return the
         * first valid file found on private storage.
         */
        $uploadedPhotos = BLDocumentUpload::query()
            ->where('lead_id', $lead->lead_id)
            ->where('applicant_sno', $applicant->applicant_sno)
            ->where('document_name', self::PROFILE_DOCUMENT_NAME)
            ->whereNotNull('document')
            ->orderByDesc('id')
            ->cursor();

        foreach ($uploadedPhotos as $uploadedPhoto) {
            $documentPhoto = $this->buildPhotoPayload(
                path: $uploadedPhoto->document,
                source: 'document_upload',
                leadUuid: (string) $lead->uuid,
                applicantId: (int) $applicant->id
            );

            if ($documentPhoto !== null) {
                return array_merge($documentPhoto, [
                    'document_upload_uuid' =>
                        $uploadedPhoto->uuid,
                ]);
            }
        }

        /*
         * No valid image found.
         * PDF template can display an empty photo frame.
         */
        return [
            'available' => false,
            'source' => null,
            'file_path' => null,
            'file_name' => null,
            'mime_type' => null,
            'data_uri' => null,
            'document_upload_uuid' => null,
        ];
    }

    private function buildPhotoPayload(
        ?string $path,
        string $source,
        string $leadUuid,
        int $applicantId
    ): ?array {
        $path = trim((string) $path);

        if ($path === '') {
            return null;
        }

        try {
            $disk = Storage::disk(self::STORAGE_DISK);

            if (! $disk->exists($path)) {
                return null;
            }

            $content = $disk->get($path);

            if ($content === '') {
                return null;
            }

            $mimeType = $this->detectMimeType(
                path: $path,
                content: $content
            );

            if (! in_array(
                $mimeType,
                self::ALLOWED_MIME_TYPES,
                true
            )) {
                return null;
            }

            return [
                'available' => true,
                'source' => $source,
                'file_path' => $path,
                'file_name' => basename($path),
                'mime_type' => $mimeType,
                'data_uri' => sprintf(
                    'data:%s;base64,%s',
                    $mimeType,
                    base64_encode($content)
                ),
                'document_upload_uuid' => null,
            ];
        } catch (Throwable $exception) {
            Log::warning('Applicant photo resolution failed.', [
                'lead_uuid' => $leadUuid,
                'applicant_id' => $applicantId,
                'source' => $source,
                'file_path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function detectMimeType(
        string $path,
        string $content
    ): string {
        try {
            $mimeType = Storage::disk(
                self::STORAGE_DISK
            )->mimeType($path);

            if (is_string($mimeType) && $mimeType !== '') {
                return strtolower($mimeType);
            }
        } catch (Throwable) {
            // Use file content detection below.
        }

        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);

        return strtolower(
            $fileInfo->buffer($content)
                ?: 'application/octet-stream'
        );
    }
}