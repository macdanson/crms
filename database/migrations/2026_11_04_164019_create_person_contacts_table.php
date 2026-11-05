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
        Schema::create('person_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->string('type', 20);
            $table->text('value_ciphertext');
            $table->string('value_masked', 60);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_contacts');
    }
};
