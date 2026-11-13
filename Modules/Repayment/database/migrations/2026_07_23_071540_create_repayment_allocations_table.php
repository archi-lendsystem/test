<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'repayment_allocations',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public API/audit identifier.
                 */
                $table->uuid('uuid')->unique();

                $table->unsignedBigInteger(
                    'repayment_transaction_id'
                );

                $table->unsignedBigInteger(
                    'repayment_installment_id'
                );

                /*
                 * INTEREST
                 * PRINCIPAL
                 *
                 * Charges belong to their separate module.
                 */
                $table->string('component', 20);

                /*
                 * Always stored as a positive amount.
                 *
                 * Direction parent transaction type se
                 * determine hogi.
                 */
                $table->decimal('amount', 18, 2);

                /*
                 * Waterfall sequence inside one transaction.
                 */
                $table->unsignedSmallInteger(
                    'allocation_order'
                );

                $table->json('metadata')->nullable();

                $table->timestamps();

                /*
                 * Same transaction, installment and component
                 * combination duplicate nahi ho sakta.
                 */
                $table->unique(
                    [
                        'repayment_transaction_id',
                        'repayment_installment_id',
                        'component',
                    ],
                    'repay_alloc_txn_inst_comp_unique'
                );

                /*
                 * Allocation order one transaction mein unique.
                 */
                $table->unique(
                    [
                        'repayment_transaction_id',
                        'allocation_order',
                    ],
                    'repay_alloc_txn_order_unique'
                );

                /*
                 * Installment allocation history.
                 */
                $table->index(
                    [
                        'repayment_installment_id',
                        'component',
                    ],
                    'repay_alloc_inst_comp_idx'
                );

                $table->foreign(
                    'repayment_transaction_id',
                    'repay_alloc_transaction_fk'
                )
                    ->references('id')
                    ->on('repayment_transactions')
                    ->restrictOnDelete();

                $table->foreign(
                    'repayment_installment_id',
                    'repay_alloc_installment_fk'
                )
                    ->references('id')
                    ->on('repayment_installments')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repayment_allocations');
    }
};