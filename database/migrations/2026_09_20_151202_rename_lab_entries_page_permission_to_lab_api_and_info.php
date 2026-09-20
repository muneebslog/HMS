<?php

use App\Enums\UserRole;
use App\Services\PageAccessService;
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

        $rolesWithNewRoute = DB::table('role_page_permissions')
            ->where('route_name', 'lab-api-and-info')
            ->pluck('role')
            ->all();

        DB::table('role_page_permissions')
            ->where('route_name', 'lab-entries')
            ->whereNotIn('role', $rolesWithNewRoute)
            ->update(['route_name' => 'lab-api-and-info', 'updated_at' => now()]);

        DB::table('role_page_permissions')
            ->where('route_name', 'lab-entries')
            ->delete();

        foreach (UserRole::cases() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('role_page_permissions')) {
            return;
        }

        $rolesWithOldRoute = DB::table('role_page_permissions')
            ->where('route_name', 'lab-entries')
            ->pluck('role')
            ->all();

        DB::table('role_page_permissions')
            ->where('route_name', 'lab-api-and-info')
            ->whereNotIn('role', $rolesWithOldRoute)
            ->update(['route_name' => 'lab-entries', 'updated_at' => now()]);

        DB::table('role_page_permissions')
            ->where('route_name', 'lab-api-and-info')
            ->delete();

        foreach (UserRole::cases() as $role) {
            app(PageAccessService::class)->clearCacheForRole($role);
        }
    }
};
