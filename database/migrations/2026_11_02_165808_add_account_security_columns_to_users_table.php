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
            $table->string('staff_number', 32)->nullable()->unique()->after('name');
            $table->string('job_title', 64)->nullable()->after('staff_number');
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
            $table->dropUnique(['staff_number']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'staff_number',
                'job_title',
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
