<?php

namespace Modules\ESign\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\BLReports\Enums\BLReportStatus;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Models\BLReport;
use Modules\ESign\Enums\DocumentStatus;
use Modules\ESign\Exceptions\ESignDomainException;
use Modules\ESign\Models\ESignDocument;
use Modules\ESign\Models\ESignSession;

final class BLCombinedDocumentService
{
    public const DOCUMENT_TYPE = 'bl_combined_package';

    public function __construct(
        private readonly PdfMerger $merger,
        private readonly BLSanctionPdfSource $sanctionSource,
        private readonly ESignWorkflow $workflow,
    ) {
    }

    /**
     * Build/reuse the complete BL package and create the borrower's signing session.
     *
     * @return array{document:ESignDocument, session:ESignSession}
     */
    public function initiate(string $leadUuid, ?int $initiatedBy = null): array
    {
        $leadUuid = trim($leadUuid);

        if ($leadUuid === '') {
            throw new ESignDomainException('lead_uuid is required.', 422);
        }

        $sources = $this->sourceDocuments($leadUuid);
        $sourceHash = hash('sha256', implode('|', array_map(
            static fn (array $source): string => $source['checksum'],
            $sources,
        )));

        $existing = ESignDocument::query()
            ->where('lead_uuid', $leadUuid)
            ->where('document_type', self::DOCUMENT_TYPE)
            ->latest('version')
            ->first();

        if (
            $existing !== null
            && data_get($existing->metadata, 'source_hash') === $sourceHash
            && ! $existing->isCompleted()
        ) {
            return [
                'document' => $existing,
                'session' => $this->workflow->startBorrowerSigning($existing),
            ];
        }

        $merged = $this->merger->merge(array_map(
            static fn (array $source): array => [
                'name' => $source['name'],
                'contents' => $source['contents'],
            ],
            $sources,
        ));

        $max = (int) config('esign.storage.max_pdf_bytes', 15728640);
        if (strlen($merged) > $max) {
            throw new ESignDomainException(
                sprintf('Combined BL package exceeds the configured eSign PDF limit of %d bytes.', $max),
                422,
            );
        }

        $version = ((int) ($existing?->version ?? 0)) + 1;
        $disk = (string) config('esign.storage.combined_disk', 'private');
        $root = trim((string) config('esign.storage.directory', 'private/esign'), '/');
        $safeLead = preg_replace('/[^A-Za-z0-9_-]/', '_', $leadUuid) ?: 'lead';
        $path = sprintf(
            '%s/bl/%s/combined-v%d-%s.pdf',
            $root,
            $safeLead,
            $version,
            substr($sourceHash, 0, 16),
        );

        if (! Storage::disk($disk)->put($path, $merged, [
            'visibility' => 'private',
            'ContentType' => 'application/pdf',
        ])) {
            throw new ESignDomainException('Combined BL package could not be stored.', 500);
        }

        $document = DB::transaction(function () use (
            $leadUuid,
            $version,
            $disk,
            $path,
            $sourceHash,
            $sources,
            $initiatedBy,
        ): ESignDocument {
            return ESignDocument::query()->create([
                'uuid' => (string) Str::uuid(),
                'lead_uuid' => $leadUuid,
                'document_type' => self::DOCUMENT_TYPE,
                'version' => $version,
                'original_document_disk' => $disk,
                'original_document_path' => $path,
                'status' => DocumentStatus::Generated,
                'metadata' => [
                    'source_hash' => $sourceHash,
                    'initiated_by' => $initiatedBy,
                    'source_documents' => array_map(
                        static fn (array $source): array => [
                            'type' => $source['type'],
                            'name' => $source['name'],
                            'id' => $source['id'],
                            'checksum' => $source['checksum'],
                        ],
                        $sources,
                    ),
                    'signature_layout' => [
                        'borrower' => 'left',
                        'lender' => 'right',
                    ],
                ],
            ]);
        }, 3);

        return [
            'document' => $document,
            'session' => $this->workflow->startBorrowerSigning($document),
        ];
    }

    /**
     * Order: Sanction Letter, Loan Application, Loan Agreement, DPN,
     * Key Facts Statement, Declaration.
     *
     * @return array<int, array{type:string,name:string,id:int,checksum:string,contents:string}>
     */
    private function sourceDocuments(string $leadUuid): array
    {
        $sanction = $this->sanctionSource->generate($leadUuid);
        $sources = [[
            'type' => 'sanction_letter',
            'name' => 'Sanction Letter',
            'id' => $sanction['sanction_id'],
            'checksum' => $sanction['checksum'],
            'contents' => $sanction['contents'],
        ]];

        foreach (BLReportType::ordered() as $type) {
            $report = BLReport::query()
                ->where('lead_uuid', $leadUuid)
                ->where('report_type', $type->value)
                ->where('status', BLReportStatus::GENERATED->value)
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->first();

            if ($report === null || empty($report->file_path)) {
                throw new ESignDomainException(
                    "{$type->label()} must be generated before starting the combined eSign.",
                    409,
                );
            }

            $diskName = trim((string) ($report->storage_disk ?: 'private')) ?: 'private';
            $disk = Storage::disk($diskName);

            if (! $disk->exists($report->file_path)) {
                throw new ESignDomainException("{$type->label()} PDF file is missing.", 409);
            }

            $contents = $disk->get($report->file_path);
            if (! is_string($contents) || strlen($contents) < 5 || substr($contents, 0, 5) !== '%PDF-') {
                throw new ESignDomainException("{$type->label()} is not a valid stored PDF.", 409);
            }

            $checksum = hash('sha256', $contents);
            $expected = strtolower(trim((string) $report->checksum));
            if ($expected === '' || ! hash_equals($expected, strtolower($checksum))) {
                throw new ESignDomainException("{$type->label()} checksum verification failed.", 409);
            }

            $sources[] = [
                'type' => strtolower($type->value),
                'name' => $report->document_name ?: $type->label(),
                'id' => (int) $report->getKey(),
                'checksum' => $checksum,
                'contents' => $contents,
            ];
        }

        return $sources;
    }
}
