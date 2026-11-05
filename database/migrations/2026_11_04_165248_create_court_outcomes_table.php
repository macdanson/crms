<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Outcomes are never overwritten: a later outcome
     * supersedes an earlier one, so appeal and reversal history stays (§4.3).
     */
    public function up(): void
    {
        Schema::create('court_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_proceeding_id')->constrained()->restrictOnDelete();
            $table->foreignId('case_person_id')->constrained('case_people')->restrictOnDelete();
            $table->string('outcome', 30);
            $table->date('outcome_date');
            $table->string('source', 160);
            $table->string('source_reference', 120)->nullable();
            $table->string('verification_status', 20);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_note', 500)->nullable();
            $table->foreignId('supersedes_id')->nullable()->constrained('court_outcomes')->restrictOnDelete();

            $table->index(['case_person_id', 'verification_status']);
            $table->index(['outcome', 'verification_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('court_outcomes');
    }
};
