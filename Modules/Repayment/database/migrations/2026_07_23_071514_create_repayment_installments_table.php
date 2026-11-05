<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'repayment_installments',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public API identifier.
                 */
                $table->uuid('uuid')->unique();

                $table->unsignedBigInteger(
                    'repayment_account_id'
                );

                /*
                 * Original amortization source.
                 */
                $table->unsignedBigInteger('amortization_id');

                $table->unsignedInteger('schedule_version')
                    ->default(1);

                /*
                 * Dynamic sequence:
                 * 1, 2, 3 ... N
                 */
                $table->unsignedInteger(
                    'installment_number'
                );

                $table->date('due_date');

                /*
                 * Frozen schedule components.
                 */
                $table->decimal(
                    'opening_principal',
                    18,
                    2
                );

                $table->decimal('principal_due', 18, 2);

                $table->decimal('interest_due', 18, 2);

                $table->decimal(
                    'installment_amount',
                    18,
                    2
                );

                $table->decimal(
                    'closing_principal',
                    18,
                    2
                );

                /*
                 * Actual allocated payment totals.
                 */
                $table->decimal('principal_paid', 18, 2)
                    ->default(0);

                $table->decimal('interest_paid', 18, 2)
                    ->default(0);

                /*
                 * Initial value:
                 * principal_due + interest_due
                 */
                $table->decimal(
                    'outstanding_amount',
                    18,
                    2
                );

                /*
                 * UNPAID
                 * PARTIAL
                 * PAID
                 */
                $table->string('payment_status', 20)
                    ->default('UNPAID');

                /*
                 * UPCOMING
                 * DUE
                 * OVERDUE
                 *
                 * DPD count intentionally absent.
                 */
                $table->string('due_status', 20)
                    ->default('UPCOMING');

                $table->timestamp('last_payment_at')
                    ->nullable();

                $table->timestamp('fully_paid_at')
                    ->nullable();

                /*
                 * Original amortization row for audit.
                 */
                $table->json('schedule_snapshot')
                    ->nullable();

                $table->timestamps();

                /*
                 * Same schedule version mein installment number
                 * duplicate nahi ho sakta.
                 */
                $table->unique(
                    [
                        'repayment_account_id',
                        'schedule_version',
                        'installment_number',
                    ],
                    'repay_inst_account_version_number_unique'
                );

                /*
                 * Schedule listing.
                 */
                $table->index(
                    [
                        'repayment_account_id',
                        'due_date',
                    ],
                    'repay_inst_account_due_idx'
                );

                /*
                 * Daily due-status refresh and reporting.
                 */
                $table->index(
                    [
                        'due_status',
                        'due_date',
                    ],
                    'repay_inst_due_status_date_idx'
                );

                /*
                 * General payment-status filtering.
                 */
                $table->index(
                    [
                        'payment_status',
                        'due_date',
                    ],
                    'repay_inst_payment_status_date_idx'
                );

                /*
                 * Oldest unpaid/partial installment allocation.
                 */
                $table->index(
                    [
                        'repayment_account_id',
                        'payment_status',
                        'due_date',
                        'installment_number',
                    ],
                    'repay_inst_account_payment_due_idx'
                );

                $table->foreign(
                    'repayment_account_id',
                    'repay_inst_account_fk'
                )
                    ->references('id')
                    ->on('repayment_accounts')
                    ->restrictOnDelete();

                $table->foreign(
                    'amortization_id',
                    'repay_inst_amortization_fk'
                )
                    ->references('id')
                    ->on('b_l_amortizations')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repayment_installments');
    }
};