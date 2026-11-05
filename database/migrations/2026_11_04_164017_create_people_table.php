<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A person is never "a suspect": roles belong to the
     * link between a person and a case (developer guide §4).
     */
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('given_names', 120);
            $table->string('family_name', 120);
            $table->string('other_names', 120)->nullable();
            $table->string('search_name', 370)->index();
            $table->string('sex', 20)->nullable();
            $table->text('date_of_birth_ciphertext')->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->string('nationality', 80)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->foreignId('registered_district_id')->constrained('districts')->restrictOnDelete();
            $table->foreignId('registered_station_id')->constrained('stations')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('merged_into_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->timestamps();

            $table->index(['search_name', 'birth_year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
