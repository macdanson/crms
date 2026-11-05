<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The stated purpose for a sensitive action and, for
     * exceptional access, the decision on it (developer guide §8).
     */
    public function up(): void
    {
        Schema::create('access_justifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 40);
            $table->string('purpose', 40);
            $table->string('case_reference', 60)->nullable();
            $table->foreignUuid('case_id')->nullable()->constrained('criminal_cases')->restrictOnDelete();
            $table->string('explanation', 1000)->nullable();
            $table->string('status', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
            $table->index(['case_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_justifications');
    }
};
