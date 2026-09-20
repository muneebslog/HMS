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
                ->whereIn('route_name', [
                    'admin.supervisor-questions',
                    'admin.supervisor-checklist',
                ])
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
