<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'repayment_receipts',
            function (Blueprint $table): void {
                $table->id();

                /*
                 * Public API identifier.
                 */
                $table->uuid('uuid')->unique();

                /*
                 * Maximum one receipt per transaction.
                 */
                $table->unsignedBigInteger(
                    'repayment_transaction_id'
                );

                /*
                 * Example: RCP-20260723-000001
                 */
                $table->string(
                    'receipt_number',
                    60
                )->unique();

                $table->date('receipt_date');

                /*
                 * ISSUED
                 * VOID
                 */
                $table->string('status', 20)
                    ->default('ISSUED');

                /*
                 * Generated receipt PDF storage details.
                 */
                $table->string(
                    'storage_disk',
                    50
                )->nullable();

                $table->string(
                    'file_path',
                    500
                )->nullable();

                /*
                 * SHA-256 hash.
                 */
                $table->string(
                    'file_hash',
                    64
                )->nullable();

                $table->unsignedBigInteger('generated_by')
                    ->nullable();

                $table->timestamp('generated_at')
                    ->nullable();

                /*
                 * Receipt is preserved and marked VOID
                 * after payment reversal.
                 */
                $table->unsignedBigInteger('voided_by')
                    ->nullable();

                $table->timestamp('voided_at')
                    ->nullable();

                $table->text('void_reason')
                    ->nullable();

                $table->json('metadata')->nullable();

                $table->timestamps();

                $table->unique(
                    'repayment_transaction_id',
                    'repay_receipt_transaction_unique'
                );

                $table->index(
                    [
                        'status',
                        'receipt_date',
                    ],
                    'repay_receipt_status_date_idx'
                );

                $table->index(
                    'generated_by',
                    'repay_receipt_generated_by_idx'
                );

                $table->foreign(
                    'repayment_transaction_id',
                    'repay_receipt_transaction_fk'
                )
                    ->references('id')
                    ->on('repayment_transactions')
                    ->restrictOnDelete();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repayment_receipts');
    }
};