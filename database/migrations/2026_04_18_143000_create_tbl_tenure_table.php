<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tbl_tenure', function (Blueprint $table) {
            $table->id();
            $table->string('year'); // e.g. "2026-2027" or just "2026"
            $table->enum('tenure', ['APR-SEP', 'OCT-MAR']);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('created_ip', 45)->nullable();
            $table->timestamps();
        });

        // Add one record for current session
        DB::table('tbl_tenure')->insert([
            'year' => '2026',
            'tenure' => 'APR-SEP',
            'created_by' => 1,
            'created_ip' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tbl_tenure');
    }
};
