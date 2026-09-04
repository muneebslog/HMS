<?php

use App\Enums\UserRole;
use App\Models\RolePagePermission;
use App\Services\PageAccessService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $exists = RolePagePermission::query()
            ->where('role', UserRole::Management)
            ->where('route_name', 'management.approvals')
            ->exists();

        if (! $exists) {
            RolePagePermission::query()->insert([
                'role' => UserRole::Management->value,
                'route_name' => 'management.approvals',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        app(PageAccessService::class)->clearCacheForRole(UserRole::Management);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        RolePagePermission::query()
            ->where('role', UserRole::Management)
            ->where('route_name', 'management.approvals')
            ->delete();

        app(PageAccessService::class)->clearCacheForRole(UserRole::Management);
    }
};
