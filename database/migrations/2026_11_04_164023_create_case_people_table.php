<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. A link that ended stays on record.
     */
    public function up(): void
    {
        Schema::create('case_people', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('case_id')->constrained('criminal_cases')->restrictOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->string('role', 30);
            $table->string('role_status', 120)->nullable();
            $table->foreignId('linked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('linked_at');
            $table->timestamp('unlinked_at')->nullable();
            $table->foreignId('unlinked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('unlink_reason', 500)->nullable();

            $table->index(['case_id', 'unlinked_at']);
            $table->index(['person_id', 'unlinked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('case_people');
    }
};
