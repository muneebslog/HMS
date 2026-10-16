<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plain columns (no foreign key) so SQLite can add them without rebuilding
     * the punches table.
     */
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->timestamp('ignored_at')->nullable();
            $table->unsignedBigInteger('ignored_by')->nullable();
            $table->string('ignore_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropColumn(['ignored_at', 'ignored_by', 'ignore_reason']);
        });
    }
};
