<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Let the incharge nurse review slips, orders and deliveries on Medication Deliveries.
     */
    public function up(): void
    {
        $exists = RolePagePermission::query()
            ->where('role', UserRole::InchargeNurse)
            ->where('route_name', 'admin.medication-deliveries')
            ->exists();

        if (! $exists) {
            RolePagePermission::query()->insert([
                'role' => UserRole::InchargeNurse->value,
                'route_name' => 'admin.medication-deliveries',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PageAccessService::class)->clearCacheForRole(UserRole::InchargeNurse);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()
            ->where('role', UserRole::InchargeNurse)
            ->where('route_name', 'admin.medication-deliveries')
            ->delete();

        app(PageAccessService::class)->clearCacheForRole(UserRole::InchargeNurse);
    }
};
