<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esign_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('esign_session_id')
                ->nullable()
                ->constrained('esign_sessions')
                ->nullOnDelete();
            $table->uuid('correlation_id')->index();
            $table->string('direction', 16);
            $table->string('action', 64);
            $table->string('method', 12)->nullable();
            $table->string('endpoint', 1024);
            $table->longText('request_payload')->nullable();
            $table->longText('response_payload')->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['esign_session_id', 'created_at'], 'esign_log_session_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esign_logs');
    }
};
