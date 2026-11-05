<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Who changed which field, when, why, and what it said
     * before (encrypted: the old value can be as sensitive as the new) (§13.1).
     */
    public function up(): void
    {
        Schema::create('record_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('record_type', 50);
            $table->string('record_id', 64);
            $table->string('field', 60);
            $table->text('previous_value_ciphertext')->nullable();
            $table->text('new_value_ciphertext')->nullable();
            $table->string('reason', 500);
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('corrected_at');

            $table->index(['record_type', 'record_id', 'corrected_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('record_corrections');
    }
};
