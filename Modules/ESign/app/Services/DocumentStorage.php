<?php

namespace Modules\ESign\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Modules\ESign\Enums\SignerType;
use Modules\ESign\Exceptions\ESignDomainException;
use Modules\ESign\Models\ESignDocument;

final class DocumentStorage
{
    public function assertValidPdf(string $disk, string $path): void
    {
        $filesystem = Storage::disk($disk);

        if (! $filesystem->exists($path)) {
            throw new ESignDomainException('Agreement PDF was not found on the configured disk.', 422);
        }

        $size = $filesystem->size($path);
        $max = (int) config('esign.storage.max_pdf_bytes', 15728640);

        if ($size < 5 || $size > $max) {
            throw new ESignDomainException('Agreement PDF size is outside the allowed limit.', 422);
        }

        $stream = $filesystem->readStream($path);

        if ($stream === false) {
            throw new ESignDomainException('Agreement PDF could not be read.', 422);
        }

        $signature = fread($stream, 5);
        fclose($stream);

        if ($signature !== '%PDF-') {
            throw new ESignDomainException('Agreement file is not a valid PDF.', 422);
        }
    }

    public function contentsForSigning(ESignDocument $document, SignerType $signerType): string
    {
        $disk = $document->signingSourceDisk($signerType);
        $path = $document->signingSourcePath($signerType);

        if ($disk === '' || $path === '') {
            throw new ESignDomainException('The required signed agreement is not available.', 409);
        }

        $this->assertValidPdf($disk, $path);

        $contents = Storage::disk($disk)->get($path);

        if (! is_string($contents)) {
            throw new ESignDomainException('Agreement PDF could not be loaded.', 500);
        }

        return $contents;
    }

    /** @return array{disk: string, path: string} */
    public function storeSignedPdf(ESignDocument $document, SignerType $signerType, string $contents): array
    {
        $max = (int) config('esign.storage.max_pdf_bytes', 15728640);

        if (strlen($contents) < 5 || strlen($contents) > $max || substr($contents, 0, 5) !== '%PDF-') {
            throw new ESignDomainException('Provider returned an invalid signed PDF.', 502);
        }

        $disk = (string) config('esign.storage.signed_disk', 'local');
        $root = trim((string) config('esign.storage.directory', 'private/esign'), '/');
        $stage = $signerType === SignerType::Borrower ? 'borrower-signed' : 'final-signed';
        $path = sprintf(
            '%s/%s/%s-v%d-%s.pdf',
            $root,
            $document->uuid,
            $document->uuid,
            $document->version,
            $stage,
        );

        $stored = Storage::disk($disk)->put($path, $contents, [
            'visibility' => 'private',
            'ContentType' => 'application/pdf',
        ]);

        if (! $stored) {
            throw new ESignDomainException('Signed PDF could not be stored.', 500);
        }

        return ['disk' => $disk, 'path' => $path];
    }

    public function filesystem(string $disk): Filesystem
    {
        return Storage::disk($disk);
    }
}
