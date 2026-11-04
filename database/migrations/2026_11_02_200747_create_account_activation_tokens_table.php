<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Activation links live apart from password reset tokens so that a reset
     * request cannot cancel a pending invitation, and a short-lived reset
     * token can never be used as a long-lived activation link.
     */
    public function up(): void
    {
        Schema::create('account_activation_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_activation_tokens');
    }
};
