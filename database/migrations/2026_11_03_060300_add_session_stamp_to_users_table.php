<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each session stores the stamp that was current when it signed in.
     * Changing the stamp ends every other session, whatever the session
     * driver (see App\Services\SessionPolicy).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('session_stamp', 64)->nullable()->after('remember_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_stamp');
        });
    }
};
