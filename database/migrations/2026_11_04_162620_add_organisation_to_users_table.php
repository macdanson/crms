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
            $table->foreignId('district_id')->nullable()->after('job_title')->constrained()->nullOnDelete();
            $table->foreignId('station_id')->nullable()->after('district_id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('station_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('station_id');
            $table->dropConstrainedForeignId('district_id');
        });
    }
};
