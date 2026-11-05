<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The analytics layer (developer guide §18): aggregate
     * counts only, with no way back to a person or a case.
     */
    public function up(): void
    {
        Schema::create('statistic_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('period');
            $table->foreignId('district_id')->constrained()->restrictOnDelete();
            $table->foreignId('offence_category_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->unsignedInteger('case_count');
            $table->timestamp('generated_at');

            $table->unique(['period', 'district_id', 'offence_category_id', 'status'], 'statistic_snapshots_cell_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statistic_snapshots');
    }
};
