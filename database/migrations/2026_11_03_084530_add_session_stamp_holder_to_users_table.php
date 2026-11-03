<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * session_stamp_holder identifies (by keyed hash) the session that ended
     * the others, so it stays signed in even if a request it had in flight
     * saves the old stamp back. Every user also gets a stamp now, so signing
     * in never has to create one.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('session_stamp_holder', 64)->nullable()->after('session_stamp');
        });

        DB::table('users')->whereNull('session_stamp')->orderBy('id')->lazyById()->each(function (object $user): void {
            DB::table('users')->where('id', $user->id)->update(['session_stamp' => Str::random(64)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('session_stamp_holder');
        });
    }
};
