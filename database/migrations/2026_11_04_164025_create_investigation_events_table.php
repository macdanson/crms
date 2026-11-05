<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The case's own append-only history.
     */
    public function up(): void
    {
        Schema::create('investigation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('case_id')->constrained('criminal_cases')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->text('summary_ciphertext');
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['case_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investigation_events');
    }
};
