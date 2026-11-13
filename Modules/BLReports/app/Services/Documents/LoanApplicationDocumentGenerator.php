<?php

namespace Modules\BLReports\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\BLReports\Contracts\BLReportDocumentGenerator;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Throwable;

final class LoanApplicationDocumentGenerator implements BLReportDocumentGenerator
{
    public function type(): BLReportType
    {
        return BLReportType::LOAN_APPLICATION;
    }

    /**
     * Generate Loan Application Form PDF.
     */
    public function generate(array $data): string
    {
        try {
            $pdfContent = Pdf::loadView(
                'blreports::pdf.loan-application',
                $data
            )
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isHtml5ParserEnabled', true)
                ->output();

            if ($pdfContent === '') {
                throw new BLReportException(
                    message: 'Loan application PDF is empty.',
                    statusCode: 500,
                    errorCode: 'LOAN_APPLICATION_PDF_EMPTY'
                );
            }

            return $pdfContent;
        } catch (BLReportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BLReportException(
                message: 'Unable to generate loan application report.',
                statusCode: 500,
                errorCode: 'LOAN_APPLICATION_GENERATION_FAILED',
                previous: $exception
            );
        }
    }
}