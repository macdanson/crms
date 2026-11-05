<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A possible match for a person to review, never an
     * automatic merge (§5.1). The pair is stored in a fixed order so it can
     * only be raised once.
     */
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('first_person_id')->constrained('people')->restrictOnDelete();
            $table->foreignUuid('second_person_id')->constrained('people')->restrictOnDelete();
            $table->string('reason', 40);
            $table->unsignedTinyInteger('confidence');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['first_person_id', 'second_person_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('duplicate_candidates');
    }
};
