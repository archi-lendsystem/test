<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payday_loan_partners', function (Blueprint $table) {
            $table->string('pull_client_id', 100)
                ->nullable()
                ->unique()
                ->after('is_active');

            $table->string('pull_client_secret_hash', 255)
                ->nullable()
                ->after('pull_client_id');

            $table->boolean('pull_credentials_enabled')
                ->default(false)
                ->after('pull_client_secret_hash')
                ->index();

            $table->timestamp('pull_credentials_generated_at')
                ->nullable()
                ->after('pull_credentials_enabled');

            $table->timestamp('pull_credentials_rotated_at')
                ->nullable()
                ->after('pull_credentials_generated_at');

            $table->timestamp('pull_last_authenticated_at')
                ->nullable()
                ->after('pull_credentials_rotated_at');

            $table->timestamp('pull_last_failed_at')
                ->nullable()
                ->after('pull_last_authenticated_at');

            $table->unsignedInteger('pull_failed_attempts')
                ->default(0)
                ->after('pull_last_failed_at');
        });
    }

    
    public function down(): void
    {
        Schema::table('payday_loan_partners', function (Blueprint $table) {
            $table->dropUnique(['pull_client_id']);

            $table->dropColumn([
                'pull_client_id',
                'pull_client_secret_hash',
                'pull_credentials_enabled',
                'pull_credentials_generated_at',
                'pull_credentials_rotated_at',
                'pull_last_authenticated_at',
                'pull_last_failed_at',
                'pull_failed_attempts',
            ]);
        });
    }
};
