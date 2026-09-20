<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('role_page_permissions')) {
            DB::table('role_page_permissions')
                ->where('route_name', 'display.stock')
                ->delete();
        }

        if (Schema::hasTable('station_sessions')) {
            DB::table('station_sessions')
                ->where('station', 'stock')
                ->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
