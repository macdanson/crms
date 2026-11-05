<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The number itself is only ever stored encrypted, with
     * a keyed token for exact lookup and a masked form for display (§10, §11).
     */
    public function up(): void
    {
        Schema::create('person_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('person_id')->constrained('people')->restrictOnDelete();
            $table->string('type', 30);
            $table->text('identifier_ciphertext');
            $table->string('identifier_lookup_hmac', 64);
            $table->string('identifier_masked', 40);
            $table->string('issuing_country', 80)->nullable();
            $table->string('issuing_authority', 120)->nullable();
            $table->string('verification_status', 30)->default('unverified');
            $table->string('verification_source', 120)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['type', 'identifier_lookup_hmac']);
            $table->unique(['person_id', 'type', 'identifier_lookup_hmac']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('person_identities');
    }
};
