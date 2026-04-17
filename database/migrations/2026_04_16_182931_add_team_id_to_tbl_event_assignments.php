<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_event_assignments', function (Blueprint $table) {
            // Nullable: NULL means individual assignment, a value means team-shared assignment
            $table->unsignedBigInteger('team_id')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('tbl_event_assignments', function (Blueprint $table) {
            $table->dropColumn('team_id');
        });
    }
};
