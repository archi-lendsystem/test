<?php

namespace Modules\BLReports\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\BLReports\Contracts\BLReportDocumentGenerator;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Throwable;

final class DeclarationDocumentGenerator implements BLReportDocumentGenerator
{
    /**
     * Report type handled by this generator.
     */
    public function type(): BLReportType
    {
        return BLReportType::DECLARATION;
    }

    /**
     * Generate Declaration PDF.
     */
    public function generate(array $data): string
    {
        try {
            $pdfContent = Pdf::loadView(
                'blreports::pdf.declaration',
                $data
            )
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isHtml5ParserEnabled', true)
                ->output();

            if ($pdfContent === '') {
                throw new BLReportException(
                    message: 'Declaration PDF is empty.',
                    statusCode: 500,
                    errorCode: 'DECLARATION_PDF_EMPTY'
                );
            }

            return $pdfContent;
        } catch (BLReportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BLReportException(
                message: 'Unable to generate declaration.',
                statusCode: 500,
                errorCode: 'DECLARATION_GENERATION_FAILED',
                previous: $exception
            );
        }
    }
}