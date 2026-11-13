<?php

namespace Modules\BLReports\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\BLReports\Contracts\BLReportDocumentGenerator;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Throwable;

final class KeyFactsStatementDocumentGenerator implements BLReportDocumentGenerator
{
    public function type(): BLReportType
    {
        return BLReportType::KEY_FACT_STATEMENT;
    }

    public function generate(array $data): string
    {
        try {
            $pdfContent = Pdf::loadView(
                'blreports::pdf.key-facts-statement',
                $data
            )
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isHtml5ParserEnabled', true)
                ->output();

            if ($pdfContent === '') {
                throw new BLReportException(
                    message: 'KFS PDF is empty.',
                    statusCode: 500,
                    errorCode: 'KFS_PDF_EMPTY'
                );
            }

            return $pdfContent;
        } catch (BLReportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BLReportException(
                message: 'Unable to generate KFS.',
                statusCode: 500,
                errorCode: 'KFS_GENERATION_FAILED',
                previous: $exception
            );
        }
    }
}