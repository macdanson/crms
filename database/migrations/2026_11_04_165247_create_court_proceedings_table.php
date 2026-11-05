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
        Schema::create('court_proceedings', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('case_id')->constrained('criminal_cases')->restrictOnDelete();
            $table->string('court_name', 160);
            $table->string('court_reference', 80);
            $table->string('status', 30);
            $table->date('filed_on')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('case_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('court_proceedings');
    }
};
