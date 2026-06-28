<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX shifts_status_open_unique ON shifts (status) WHERE status = 'open'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS shifts_status_open_unique');

        Schema::table('shifts', function (Blueprint $table) {
            $table->index(['user_id', 'status']);
        });
    }
};
