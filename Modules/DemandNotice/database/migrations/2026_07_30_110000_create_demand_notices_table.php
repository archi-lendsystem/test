<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_notices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('repayment_account_id');
            $table->foreign('repayment_account_id')->references('id')->on('repayment_accounts')->cascadeOnDelete();

            $table->string('notice_number')->unique();
            $table->string('notice_type', 20); // FIRST / SECOND / FINAL

            $table->date('notice_date');
            $table->date('due_by_date');
            $table->date('overdue_since_date')->nullable();
            $table->decimal('overdue_amount', 15, 2);

            $table->string('status', 20)->default('GENERATED'); // GENERATED / SENT
            $table->string('sent_via', 20)->nullable(); // EMAIL / SMS / POST / WHATSAPP
            $table->timestamp('sent_at')->nullable();

            $table->string('pdf_path')->nullable();
            $table->text('remarks')->nullable();

            $table->unsignedBigInteger('generated_by')->nullable();
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demand_notices');
    }
};
