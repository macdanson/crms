<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. What moved in each merge, so the history is kept and
     * a wrong merge can be traced and put right (§5.1).
     */
    public function up(): void
    {
        Schema::create('person_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('source_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignUuid('target_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignId('duplicate_candidate_id')->constrained()->restrictOnDelete();
            $table->json('moved_records');
            $table->string('reason', 500);
            $table->foreignId('merged_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('merged_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_merges');
    }
};
