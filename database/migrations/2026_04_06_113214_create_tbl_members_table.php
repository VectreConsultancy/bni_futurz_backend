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
        Schema::create('tbl_members', function (Blueprint $table) {
            $table->id('member_id');
            $table->json('category_id')->nullable();
            $table->string('team_id', 50)->nullable();
            $table->string('member_name', 50)->nullable();
            $table->string('mobile_no', 20)->unique()->nullable();
            $table->string('otp', 6)->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_members');
    }
};
