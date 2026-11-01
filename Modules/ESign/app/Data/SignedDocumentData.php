<?php

namespace Modules\ESign\Data;

final class SignedDocumentData
{
    public function __construct(
        public readonly string $contents,
        public readonly string $mimeType = 'application/pdf',
    ) {
    }
}
