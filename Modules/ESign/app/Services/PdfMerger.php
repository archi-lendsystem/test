<?php

namespace Modules\ESign\Services;

use Modules\ESign\Exceptions\ESignDomainException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

final class PdfMerger
{
    /**
     * @param array<int, array{name:string, contents:string}> $documents
     */
    public function merge(array $documents): string
    {
        if ($documents === []) {
            throw new ESignDomainException('No PDFs were supplied for merging.', 422);
        }

        if (! class_exists(Fpdi::class)) {
            throw new ESignDomainException(
                'PDF merge dependency is missing. Install setasign/fpdi and setasign/fpdf.',
                500,
            );
        }

        try {
            $pdf = new Fpdi();
            $pdf->SetAutoPageBreak(false);

            foreach ($documents as $document) {
                $contents = (string) ($document['contents'] ?? '');
                $name = (string) ($document['name'] ?? 'document');

                if (strlen($contents) < 5 || substr($contents, 0, 5) !== '%PDF-') {
                    throw new ESignDomainException("{$name} is not a valid PDF.", 422);
                }

                $pageCount = $pdf->setSourceFile(StreamReader::createByString($contents));

                for ($page = 1; $page <= $pageCount; $page++) {
                    $template = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($template);
                    $orientation = $size['width'] > $size['height'] ? 'L' : 'P';

                    $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                    $pdf->useTemplate($template, 0, 0, $size['width'], $size['height'], true);
                }
            }

            $merged = $pdf->Output('S');

            if (! is_string($merged) || strlen($merged) < 5 || substr($merged, 0, 5) !== '%PDF-') {
                throw new ESignDomainException('The merged eSign package is not a valid PDF.', 500);
            }

            return $merged;
        } catch (ESignDomainException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new ESignDomainException(
                'Unable to merge the BL reports and sanction letter: '.$exception->getMessage(),
                500,
            );
        }
    }
}
