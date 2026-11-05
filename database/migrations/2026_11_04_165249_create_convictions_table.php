<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A row exists only because a verified court outcome
     * says so. Each outcome can only ever produce one.
     */
    public function up(): void
    {
        Schema::create('convictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_outcome_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('case_person_id')->constrained('case_people')->restrictOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->foreignUuid('case_id')->constrained('criminal_cases')->restrictOnDelete();
            $table->date('convicted_on');
            $table->timestamp('overturned_at')->nullable();
            $table->foreignId('overturned_by_outcome_id')->nullable()->constrained('court_outcomes')->restrictOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['person_id', 'overturned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('convictions');
    }
};
