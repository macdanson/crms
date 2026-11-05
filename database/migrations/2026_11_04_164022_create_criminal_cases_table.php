<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Police operational status and the record's lifecycle
     * are separate from each other and from any court outcome (§4.3, §13).
     */
    public function up(): void
    {
        Schema::create('criminal_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 60)->unique();
            $table->foreignId('station_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->foreignId('offence_category_id')->constrained()->restrictOnDelete();
            $table->date('incident_date');
            $table->string('incident_location', 255);
            $table->text('narrative_ciphertext');
            $table->string('status', 30)->default('open');
            $table->string('lifecycle', 30)->default('active');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['district_id', 'station_id', 'lifecycle']);
            $table->index(['station_id', 'created_at']);
            $table->index(['status', 'offence_category_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('criminal_cases');
    }
};
