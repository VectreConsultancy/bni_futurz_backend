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
        Schema::table('tbl_events', function (Blueprint $table) {
            $table->string('venue')->nullable()->after('date');
            $table->unsignedBigInteger('tenure_id')->nullable()->after('venue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_events', function (Blueprint $table) {
            $table->dropColumn(['venue', 'tenure_id']);
        });
    }
};
