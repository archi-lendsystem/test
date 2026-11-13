<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
 public function up(): void
{

   Schema::create('b_l_case_discusions', function (Blueprint $table) {
   $table->id();
    $table->uuid('lead_uuid');
    $table->string('lead_id');
    $table->unsignedBigInteger('created_by')->nullable();
    $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED', 'HOLD']);
    $table->unsignedBigInteger('forwarded_to')->nullable();
    $table->unsignedBigInteger('forwarded_by')->nullable();
    $table->text('remarks')->nullable();
    $table->timestamps();

    $table->index('lead_uuid');


    // Add FKs inside create table
    $table->foreign('created_by', 'fk_bcd_created_by')
        ->references('id')->on('users')->nullOnDelete();

    $table->foreign('forwarded_to', 'fk_bcd_forwarded_to')
        ->references('id')->on('users')->nullOnDelete();

    $table->foreign('forwarded_by', 'fk_bcd_forwarded_by')
        ->references('id')->on('users')->nullOnDelete();

    $table->foreign('lead_uuid', 'fk_bcd_lead_uuid')
        ->references('uuid')->on('b_l_lead_creations')->cascadeOnDelete();
});
}

public function down(): void
{
    Schema::dropIfExists('b_l_case_discusions');
}
};