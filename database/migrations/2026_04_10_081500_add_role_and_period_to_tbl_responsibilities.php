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
        Schema::table('tbl_responsibilities', function (Blueprint $table) {
            $table->unsignedBigInteger('coordinator_id')->nullable()->change();
            $table->unsignedBigInteger('role_id')->nullable()->after('coordinator_id');
            $table->tinyInteger('period')->nullable()->after('level')->comment('1=Weekly, 2=Monthly, 3=As and When Required');
            
            $table->foreign('role_id')->references('role_id')->on('master_roles')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tbl_responsibilities', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropColumn(['role_id', 'period']);
            $table->unsignedBigInteger('coordinator_id')->nullable(false)->change();
        });
    }
};
