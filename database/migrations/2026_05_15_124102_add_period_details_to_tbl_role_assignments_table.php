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
        Schema::table('tbl_role_assignments', function (Blueprint $table) {
            $table->unsignedTinyInteger('period')->default(3)->after('tenure_id'); // 1:Weekly, 2:Monthly, 3:Tenure-based
            $table->unsignedInteger('week_number')->nullable()->after('period');
            $table->unsignedInteger('month_number')->nullable()->after('week_number');
            $table->unsignedInteger('year')->nullable()->after('month_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_role_assignments', function (Blueprint $table) {
            $table->dropColumn(['period', 'week_number', 'month_number', 'year']);
        });
    }
};
