<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Lab technicians do not use MR Lookup.
     */
    public function up(): void
    {
        RolePagePermission::query()
            ->where('role', UserRole::LabTechnician)
            ->where('route_name', 'reception.mr-lookup')
            ->delete();

        app(PageAccessService::class)->clearCacheForRole(UserRole::LabTechnician);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $exists = RolePagePermission::query()
            ->where('role', UserRole::LabTechnician)
            ->where('route_name', 'reception.mr-lookup')
            ->exists();

        if (! $exists) {
            RolePagePermission::query()->insert([
                'role' => UserRole::LabTechnician->value,
                'route_name' => 'reception.mr-lookup',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PageAccessService::class)->clearCacheForRole(UserRole::LabTechnician);
    }
};
