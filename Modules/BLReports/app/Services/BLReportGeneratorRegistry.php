<?php

namespace Modules\BLReports\Services;

use Modules\BLReports\Contracts\BLReportDocumentGenerator;
use Modules\BLReports\Enums\BLReportType;
use Modules\BLReports\Exceptions\BLReportException;
use Modules\BLReports\Services\Documents\DeclarationDocumentGenerator;
use Modules\BLReports\Services\Documents\DPNDocumentGenerator;
use Modules\BLReports\Services\Documents\KeyFactsStatementDocumentGenerator;
use Modules\BLReports\Services\Documents\LoanAgreementDocumentGenerator;
use Modules\BLReports\Services\Documents\LoanApplicationDocumentGenerator;

final class BLReportGeneratorRegistry
{
    /**
     * @var array<string, BLReportDocumentGenerator>
     */
    private array $generators;

    public function __construct(
        LoanApplicationDocumentGenerator $loanApplicationGenerator,
        LoanAgreementDocumentGenerator $loanAgreementGenerator,
        DPNDocumentGenerator $dpnGenerator,
        KeyFactsStatementDocumentGenerator $keyFactsStatementGenerator,
        DeclarationDocumentGenerator $declarationGenerator
    ) {
        $this->generators = [
            $loanApplicationGenerator->type()->value =>
                $loanApplicationGenerator,

            $loanAgreementGenerator->type()->value =>
                $loanAgreementGenerator,

            $dpnGenerator->type()->value =>
                $dpnGenerator,

            $keyFactsStatementGenerator->type()->value =>
                $keyFactsStatementGenerator,

            $declarationGenerator->type()->value =>
                $declarationGenerator,
        ];
    }

    /**
     * Resolve generator for a report type.
     */
    public function get(
        BLReportType $reportType
    ): BLReportDocumentGenerator {
        $generator = $this->generators[
            $reportType->value
        ] ?? null;

        if (! $generator) {
            throw new BLReportException(
                message: 'Report generator not found.',
                statusCode: 500,
                errorCode: 'REPORT_GENERATOR_NOT_FOUND',
                context: [
                    'report_type' => $reportType->value,
                ]
            );
        }

        return $generator;
    }

    /**
     * Return generators in the required report order.
     *
     * @return array<int, BLReportDocumentGenerator>
     */
    public function all(): array
    {
        return array_map(
            fn (
                BLReportType $reportType
            ): BLReportDocumentGenerator =>
                $this->get($reportType),
            BLReportType::ordered()
        );
    }
}