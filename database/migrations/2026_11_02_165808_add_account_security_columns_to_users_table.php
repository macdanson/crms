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
        Schema::table('users', function (Blueprint $table) {
            $table->string('service_number', 32)->nullable()->unique()->after('name');
            $table->string('rank', 64)->nullable()->after('service_number');
            $table->string('status', 20)->default('pending')->index()->after('email');
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['service_number']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'service_number',
                'rank',
                'status',
                'password_changed_at',
                'last_login_at',
                'last_login_ip',
                'suspended_at',
                'suspension_reason',
            ]);
        });
    }
};
