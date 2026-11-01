<?php

namespace Modules\BLReports\Services\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Modules\BLReports\Contracts\BLReportDocumentGenerator;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Throwable;

final class LoanAgreementDocumentGenerator implements BLReportDocumentGenerator
{
    public function type(): BLReportType
    {
        return BLReportType::LOAN_AGREEMENT;
    }

    public function generate(array $data): string
    {
        try {
            $pdfContent = Pdf::loadView(
                'blreports::pdf.loan-agreement',
                $data
            )
                ->setPaper('a4', 'portrait')
                ->setOption('isRemoteEnabled', false)
                ->setOption('isHtml5ParserEnabled', true)
                ->output();

            if ($pdfContent === '') {
                throw new BLReportException(
                    message: 'Loan agreement PDF is empty.',
                    statusCode: 500,
                    errorCode: 'LOAN_AGREEMENT_PDF_EMPTY'
                );
            }

            return $pdfContent;
        } catch (BLReportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BLReportException(
                message: 'Unable to generate loan agreement.',
                statusCode: 500,
                errorCode: 'LOAN_AGREEMENT_GENERATION_FAILED',
                previous: $exception
            );
        }
    }
}