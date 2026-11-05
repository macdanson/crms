<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. The actor's place in the organisation is copied, not
     * linked: the record must still say where they worked after a transfer.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->string('record_type', 50)->nullable()->after('outcome');
            $table->string('record_id', 64)->nullable()->after('record_type');
            $table->unsignedBigInteger('actor_district_id')->nullable()->after('record_id');
            $table->unsignedBigInteger('actor_station_id')->nullable()->after('actor_district_id');
            $table->string('seal', 64)->nullable()->after('context');

            $table->index(['record_type', 'record_id', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropIndex(['record_type', 'record_id', 'occurred_at']);
            $table->dropColumn(['record_type', 'record_id', 'actor_district_id', 'actor_station_id', 'seal']);
        });
    }
};
