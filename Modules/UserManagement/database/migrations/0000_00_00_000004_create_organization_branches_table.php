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
        Schema::create('organization_branches', function (Blueprint $table) {

            $table->id();

            // Organization
            $table->foreignId('organization_id')
                ->constrained('organizations')
                ->cascadeOnDelete();

            // User Type
            $table->foreignId('user_type_id')
                ->constrained('user_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Self Reference
            $table->foreignId('parent_branch_id')
                ->nullable()
                ->constrained('organization_branches')
                ->nullOnDelete();

            $table->string('name');
            // Delhi HO, Mumbai Branch

            $table->string('branch_code')->unique();

            $table->string('type')->nullable();
            // HO, BRANCH, SUB_BRANCH

            $table->text('address')->nullable();

            $table->string('pincode', 10)->nullable();

            $table->string('city')->nullable();

            $table->string('state')->nullable();

            $table->boolean('is_active')->default(true);

            $table->softDeletes();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_branches');
    }
};