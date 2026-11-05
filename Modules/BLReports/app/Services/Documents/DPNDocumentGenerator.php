<?php

namespace Modules\BLReports\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\BLReports\Contracts\BLReportDocumentGenerator;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Throwable;

final class DPNDocumentGenerator implements BLReportDocumentGenerator
{
    public function type(): BLReportType
    {
        return BLReportType::DPN;
    }

    public function generate(array $data): string
    {
        try {
            $pdfContent = Pdf::loadView(
                'blreports::pdf.dpn',
                $data
            )
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isHtml5ParserEnabled', true)
                ->output();

            if ($pdfContent === '') {
                throw new BLReportException(
                    message: 'DPN PDF is empty.',
                    statusCode: 500,
                    errorCode: 'DPN_PDF_EMPTY'
                );
            }

            return $pdfContent;
        } catch (BLReportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BLReportException(
                message: 'Unable to generate DPN.',
                statusCode: 500,
                errorCode: 'DPN_GENERATION_FAILED',
                previous: $exception
            );
        }
    }
}