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
        if (! Schema::hasTable('role_page_permissions')) {
            return;
        }

        DB::table('role_page_permissions')
            ->where(function ($query): void {
                $query->where('route_name', 'like', 'reception.lab-tracking%')
                    ->orWhere('route_name', 'like', 'reception.ultrasound%')
                    ->orWhere('route_name', 'reception.rooms');
            })
            ->delete();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Page routes were removed; recreate via page access UI or seeders if needed.
    }
};
