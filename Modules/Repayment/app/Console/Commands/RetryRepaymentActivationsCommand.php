<?php

namespace Modules\Repayment\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\BLAmortization\Models\BLAmortization;
use Modules\Repayment\Exceptions\RepaymentActivationException;
use Modules\Repayment\Models\RepaymentAccount;
use Modules\Repayment\Services\RepaymentAccountActivationService;
use Throwable;

final class RetryRepaymentActivationsCommand extends Command
{
    protected $signature = 'repayment:retry-activations
        {--amortization= : Retry one amortization ID}
        {--lead= : Retry the latest amortization for one lead UUID}
        {--limit=100 : Maximum records to process (1-500)}';

    protected $description =
        'Retry missing repayment account activations from saved amortizations.';

    public function handle(
        RepaymentAccountActivationService $activationService
    ): int {
        $requiredTables = [
            'b_l_amortizations',
            'b_l_disbursments',
            'repayment_accounts',
            'repayment_installments',
            'repayment_status_histories',
        ];

        $missingTables = array_values(
            array_filter(
                $requiredTables,
                static fn (string $table): bool =>
                    ! Schema::hasTable($table)
            )
        );

        if ($missingTables !== []) {
            $this->components->warn(
                'Activation retry skipped. Missing tables: '
                . implode(', ', $missingTables)
            );

            return self::SUCCESS;
        }

        $amortizationOption = trim(
            (string) $this->option('amortization')
        );

        $leadUuid = trim(
            (string) $this->option('lead')
        );

        if (
            $amortizationOption !== ''
            && $leadUuid !== ''
        ) {
            $this->components->error(
                'Use either --amortization or --lead, not both.'
            );

            return self::INVALID;
        }

        $amortizationId = null;

        if ($amortizationOption !== '') {
            if (
                ! ctype_digit($amortizationOption)
                || (int) $amortizationOption < 1
            ) {
                $this->components->error(
                    '--amortization must be a positive integer.'
                );

                return self::INVALID;
            }

            $amortizationId = (int) $amortizationOption;
        }

        if (
            $leadUuid !== ''
            && ! Str::isUuid($leadUuid)
        ) {
            $this->components->error(
                '--lead must be a valid UUID.'
            );

            return self::INVALID;
        }

        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 500,
                ],
            ]
        );

        if ($limit === false) {
            $this->components->error(
                '--limit must be an integer between 1 and 500.'
            );

            return self::INVALID;
        }

        $query = BLAmortization::query()
            ->useWritePdo();

        if ($amortizationId !== null) {
            $query->whereKey($amortizationId);
        } elseif ($leadUuid !== '') {
            $query
                ->where('lead_uuid', $leadUuid)
                ->latest('id');
        } else {
            /*
             * Process only the latest saved amortization for each
             * lead and only where no repayment account exists.
             */
            $query
                ->whereIn(
                    'b_l_amortizations.id',
                    function ($subQuery): void {
                        $subQuery
                            ->selectRaw(
                                'MAX(source_amortizations.id)'
                            )
                            ->from(
                                'b_l_amortizations as source_amortizations'
                            )
                            ->whereNull(
                                'source_amortizations.deleted_at'
                            )
                            ->groupBy(
                                'source_amortizations.lead_uuid'
                            );
                    }
                )
                ->whereNotExists(
                    function ($subQuery): void {
                        $subQuery
                            ->selectRaw('1')
                            ->from('repayment_accounts')
                            ->whereColumn(
                                'repayment_accounts.lead_uuid',
                                'b_l_amortizations.lead_uuid'
                            );
                    }
                )
                ->orderBy('b_l_amortizations.id');
        }

        $amortizations = $query
            ->limit($limit)
            ->get();

        if ($amortizations->isEmpty()) {
            if (
                $amortizationId !== null
                || $leadUuid !== ''
            ) {
                $this->components->error(
                    'Matching amortization record was not found.'
                );

                return self::FAILURE;
            }

            $this->components->info(
                'No missing repayment activations were found.'
            );

            return self::SUCCESS;
        }

        $activated = 0;
        $alreadyPresent = 0;
        $failed = 0;
        $rows = [];

        foreach ($amortizations as $amortization) {
            try {
                $existingAccount = RepaymentAccount::query()
                    ->useWritePdo()
                    ->where(function ($accountQuery) use (
                        $amortization
                    ): void {
                        $accountQuery
                            ->where(
                                'amortization_id',
                                $amortization->id
                            )
                            ->orWhere(
                                'lead_uuid',
                                $amortization->lead_uuid
                            );
                    })
                    ->first();

                $account = $activationService->activate(
                    amortization: $amortization,
                    actorId: null
                );

                if ($existingAccount) {
                    $result = 'ALREADY_PRESENT';
                    $alreadyPresent++;
                } else {
                    $result = 'ACTIVATED';
                    $activated++;
                }

                $rows[] = [
                    $amortization->id,
                    $amortization->lead_uuid,
                    $result,
                    $account->uuid,
                ];
            } catch (Throwable $exception) {
                $failed++;

                $context = [
                    'amortization_id' =>
                        $amortization->id,

                    'lead_uuid' =>
                        $amortization->lead_uuid,

                    'exception_class' =>
                        $exception::class,

                    'message' =>
                        $exception->getMessage(),
                ];

                if (
                    $exception instanceof
                    RepaymentActivationException
                ) {
                    $context = array_merge(
                        $context,
                        $exception->context()
                    );
                }

                Log::error(
                    'Repayment activation retry failed.',
                    $context
                );

                $rows[] = [
                    $amortization->id,
                    $amortization->lead_uuid,
                    'FAILED',
                    Str::limit(
                        $exception->getMessage(),
                        70
                    ),
                ];
            }
        }

        $this->table(
            [
                'Amortization ID',
                'Lead UUID',
                'Result',
                'Account UUID / Error',
            ],
            $rows
        );

        $this->table(
            [
                'Processed',
                'Activated',
                'Already present',
                'Failed',
            ],
            [
                [
                    $amortizations->count(),
                    $activated,
                    $alreadyPresent,
                    $failed,
                ],
            ]
        );

        if ($failed > 0) {
            $this->components->warn(
                'Some activations failed. Check application logs.'
            );

            return self::FAILURE;
        }

        $this->components->info(
            'Repayment activation retry completed.'
        );

        return self::SUCCESS;
    }
}
